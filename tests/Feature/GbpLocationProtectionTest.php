<?php

namespace Tests\Feature;

use App\Enums\GbpTokenStatus;
use App\Jobs\SyncGBPLocationJob;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Services\GBP\GBPClientFactory;
use App\Services\GBP\GBPLocationProtection;
use App\Support\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Every Google call in this file is faked; nothing here reaches the network.
 */
class GbpLocationProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected const GOOGLE = 'mybusinessbusinessinformation.googleapis.com/*';

    protected Organization $organization;

    protected Location $location;

    /**
     * @var array<string, mixed>
     */
    protected array $canonical = [
        'title' => 'さくら食堂 渋谷店',
        'phoneNumbers' => ['primaryPhone' => '03-1234-5678'],
        'websiteUri' => 'https://sakura.example.com',
        'regularHours' => ['periods' => [
            ['openDay' => 'MONDAY', 'openTime' => ['hours' => 11], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 22]],
        ]],
        'profile' => ['description' => '創業三十年の定食屋です。'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()
            ->gbpProtected($this->canonical)
            ->create([
                'organization_id' => $this->organization->id,
                'gbp_location_id' => 'locations/1234567890',
            ]);
    }

    protected function connect(?Location $location = null, array $state = []): GbpAccount
    {
        return GbpAccount::factory()
            ->forLocation($location ?? $this->location)
            ->create($state + ['gbp_account_name' => 'accounts/999']);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * Google answers a read with `$current` and accepts any write.
     *
     * @param  array<string, mixed>  $current
     */
    protected function fakeGoogle(array $current): void
    {
        Http::fake([self::GOOGLE => fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['name' => 'locations/1234567890'] + $current)
            : Http::response(['name' => 'locations/1234567890'])]);
    }

    protected function runSync(int $attempt = 1): void
    {
        $job = new SyncGBPLocationJob($this->location);

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);

        $job->setJob($queueJob)->handle(
            app(GBPClientFactory::class),
            app(GBPLocationProtection::class),
            app(Tenancy::class),
        );
    }

    protected function url(?Location $location = null): string
    {
        $location ??= $this->location;

        return "/api/v1/organizations/{$location->organization_id}/locations/{$location->id}/gbp-protection";
    }

    public function test_a_protected_field_changed_on_google_is_patched_back(): void
    {
        $this->freezeSecond();
        Log::spy();
        $this->connect();

        $this->fakeGoogle(array_replace($this->canonical, [
            'title' => '勝手に変わった店名',
            'profile' => ['description' => '誰かが書き換えた説明文'],
        ]));

        $this->runSync();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PATCH'
                && str_contains(urldecode($request->url()), 'locations/1234567890?updateMask=title,profile')
                && $request->data() === [
                    'title' => 'さくら食堂 渋谷店',
                    'profile' => ['description' => '創業三十年の定食屋です。'],
                ];
        });
        $this->assertTrue($this->location->fresh()->gbp_last_verified_at->equalTo(now()));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'changed outside')
                && $context['fields'] === ['title', 'profile.description']
                && $context['found']['title'] === '勝手に変わった店名');
    }

    public function test_a_profile_that_still_agrees_is_only_marked_verified(): void
    {
        $this->freezeSecond();
        $this->connect();

        // The same hours with the keys in another order are the same hours.
        $current = $this->canonical;
        $current['regularHours']['periods'][0] = array_reverse($current['regularHours']['periods'][0], true);
        $this->fakeGoogle($current);

        $this->runSync();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
        $this->assertTrue($this->location->fresh()->gbp_last_verified_at->equalTo(now()));
    }

    public function test_a_field_that_is_not_protected_is_left_as_google_has_it(): void
    {
        $this->location->forceFill(['gbp_protected_fields' => ['title']])->save();
        $this->connect();

        $this->fakeGoogle(array_replace($this->canonical, ['websiteUri' => 'https://elsewhere.example.com']));

        $this->runSync();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
    }

    public function test_a_field_the_snapshot_holds_nothing_for_is_not_erased(): void
    {
        $canonical = $this->canonical;
        unset($canonical['profile']);
        $this->location->forceFill(['gbp_canonical_data' => $canonical])->save();
        $this->connect();

        $this->fakeGoogle($this->canonical);

        $this->runSync();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
    }

    public function test_a_store_front_without_protection_is_not_called(): void
    {
        Http::fake();
        $this->location->forceFill(['gbp_protected_fields' => null])->save();
        $this->connect();

        $this->runSync();

        Http::assertNothingSent();
        $this->assertNull($this->location->fresh()->gbp_last_verified_at);
    }

    public function test_a_rate_limit_that_outlasts_the_retries_leaves_the_profile_unverified(): void
    {
        $this->connect();

        Http::fake([self::GOOGLE => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        $this->runSync(attempt: 4);

        $this->assertNull($this->location->fresh()->gbp_last_verified_at);
        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_the_command_queues_only_active_connected_protected_store_fronts(): void
    {
        $this->connect();

        $unprotected = Location::factory()->create(['organization_id' => $this->organization->id]);
        $this->connect($unprotected);

        $paused = Location::factory()->gbpProtected($this->canonical)->create([
            'organization_id' => $this->organization->id,
            'is_active' => false,
        ]);
        $this->connect($paused);

        $broken = Location::factory()->gbpProtected($this->canonical)->create(['organization_id' => $this->organization->id]);
        $this->connect($broken, ['token_status' => GbpTokenStatus::Expired]);

        Queue::fake([SyncGBPLocationJob::class]);

        $this->artisan('gbp:sync-locations')->assertSuccessful();

        Queue::assertPushed(SyncGBPLocationJob::class, 1);
        Queue::assertPushed(SyncGBPLocationJob::class, fn (SyncGBPLocationJob $job) => $job->location->is($this->location));
    }

    public function test_the_command_can_be_narrowed_to_one_store_front(): void
    {
        $this->connect();

        $other = Location::factory()->gbpProtected($this->canonical)->create(['organization_id' => $this->organization->id]);
        $this->connect($other);

        Queue::fake([SyncGBPLocationJob::class]);

        $this->artisan('gbp:sync-locations', ['--location-id' => $other->id])->assertSuccessful();

        Queue::assertPushed(SyncGBPLocationJob::class, 1);
        Queue::assertPushed(SyncGBPLocationJob::class, fn (SyncGBPLocationJob $job) => $job->location->is($other));
    }

    public function test_the_command_fails_for_a_store_front_it_cannot_check(): void
    {
        Queue::fake([SyncGBPLocationJob::class]);

        $this->artisan('gbp:sync-locations', ['--location-id' => $this->location->id])->assertFailed();

        Queue::assertNotPushed(SyncGBPLocationJob::class);
    }

    public function test_the_check_is_scheduled_for_two_in_the_morning_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'gbp:sync-locations'));

        $this->assertCount(1, $events);
        $this->assertSame('0 2 * * *', $events->first()->expression);
        $this->assertSame('Asia/Tokyo', $events->first()->timezone);
    }

    public function test_switching_protection_on_keeps_what_google_holds_now(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/555',
        ]);
        $this->connect($location);
        $this->fakeGoogle($this->canonical + ['storefrontAddress' => ['locality' => '渋谷区']]);

        $this->actingAs($this->member('location_admin'))
            ->putJson($this->url($location))
            ->assertOk()
            ->assertJsonPath('gbp_protection.enabled', true)
            ->assertJsonPath('gbp_protection.fields', Location::GBP_PROTECTABLE_FIELDS);

        $location->refresh();
        $this->assertSame(Location::GBP_PROTECTABLE_FIELDS, $location->gbp_protected_fields);
        $this->assertEquals($this->canonical, $location->gbp_canonical_data);
        $this->assertNotNull($location->gbp_last_verified_at);
    }

    public function test_switching_protection_off_forgets_the_snapshot(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->deleteJson($this->url())
            ->assertOk()
            ->assertJsonPath('gbp_protection.enabled', false);

        $this->location->refresh();
        $this->assertNull($this->location->gbp_protected_fields);
        $this->assertNull($this->location->gbp_canonical_data);
    }

    public function test_the_store_front_list_says_whether_it_is_protected(): void
    {
        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/organizations/{$this->organization->id}/locations/{$this->location->id}")
            ->assertOk()
            ->assertJsonPath('location.gbp_protected', true);
    }

    public function test_a_store_front_not_linked_to_a_profile_cannot_be_protected(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => null,
        ]);

        $this->actingAs($this->member('location_admin'))
            ->putJson($this->url($location))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Googleビジネスプロフィールと連携していない店舗は保護できません。');
    }

    public function test_switching_on_over_a_broken_connection_asks_for_a_reconnection(): void
    {
        Http::fake();
        $this->connect(state: ['token_status' => GbpTokenStatus::Revoked]);

        $this->actingAs($this->member('location_admin'))
            ->putJson($this->url())
            ->assertStatus(409)
            ->assertJsonPath('reconnect_required', true);

        Http::assertNothingSent();
    }

    public function test_staff_cannot_switch_protection(): void
    {
        $this->actingAs($this->member('staff'))
            ->deleteJson($this->url())
            ->assertForbidden();

        $this->assertTrue($this->location->fresh()->isGbpProtected());
    }

    public function test_another_organizations_store_front_is_not_found(): void
    {
        $foreign = Location::factory()->gbpProtected($this->canonical)->create();

        $this->actingAs($this->member('location_admin'))
            ->deleteJson("/api/v1/organizations/{$this->organization->id}/locations/{$foreign->id}/gbp-protection")
            ->assertNotFound();

        $this->assertTrue($foreign->fresh()->isGbpProtected());
    }
}
