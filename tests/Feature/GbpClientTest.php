<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\GbpApi;
use App\Enums\GbpTokenStatus;
use App\Models\Alert;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\Exceptions\GBPException;
use App\Services\GBP\GBPClientFactory;
use App\Services\GBP\GBPTokenRefresher;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every Google call in this file is faked; nothing here reaches the network.
 */
class GbpClientTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/1234567890',
        ]);

        app(Tenancy::class)->set($this->organization);
    }

    protected function account(array $state = []): GbpAccount
    {
        return GbpAccount::factory()
            ->forLocation($this->location)
            ->create($state + ['gbp_account_name' => 'accounts/999']);
    }

    protected function factory(): GBPClientFactory
    {
        return app(GBPClientFactory::class);
    }

    public function test_the_eight_apis_each_have_their_own_host_and_version(): void
    {
        $bases = array_map(fn (GbpApi $api) => $api->baseUrl(), GbpApi::cases());

        $this->assertCount(8, GbpApi::cases());
        $this->assertCount(8, array_unique($bases));

        // Reviews, local posts and media were never moved off v4.
        $this->assertSame('https://mybusiness.googleapis.com/v4', GbpApi::Legacy->baseUrl());
        $this->assertSame('https://mybusinessbusinessinformation.googleapis.com/v1', GbpApi::BusinessInformation->baseUrl());
        $this->assertSame('https://businessprofileperformance.googleapis.com/v1', GbpApi::Performance->baseUrl());
    }

    public function test_tokens_are_stored_encrypted_and_read_back_plain(): void
    {
        $account = $this->account(['access_token_encrypted' => 'ya29.plain-token']);

        $stored = (string) \DB::table('gbp_accounts')->where('id', $account->id)->value('access_token_encrypted');

        $this->assertNotSame('ya29.plain-token', $stored);
        $this->assertStringNotContainsString('ya29.plain-token', $stored);
        $this->assertSame('ya29.plain-token', $account->fresh()->accessToken());
    }

    public function test_a_connection_is_never_serialised_into_a_response(): void
    {
        $account = $this->account();

        $this->assertArrayNotHasKey('access_token_encrypted', $account->toArray());
        $this->assertArrayNotHasKey('refresh_token_encrypted', $account->toArray());
    }

    public function test_the_reviews_client_calls_v4_with_the_stored_token(): void
    {
        Http::fake([
            'mybusiness.googleapis.com/*' => Http::response(['reviews' => [['reviewId' => 'r1']]]),
        ]);

        $account = $this->account(['access_token_encrypted' => 'ya29.live']);

        $reviews = $this->factory()->reviews($account)->reviews('accounts/999', 'locations/1234567890');

        $this->assertSame([['reviewId' => 'r1']], $reviews);

        Http::assertSent(function ($request) {
            $this->assertSame('Bearer ya29.live', $request->header('Authorization')[0]);
            $this->assertStringContainsString(
                'mybusiness.googleapis.com/v4/accounts/999/locations/1234567890/reviews',
                $request->url(),
            );

            return true;
        });
    }

    public function test_a_list_endpoint_is_walked_to_the_end(): void
    {
        Http::fakeSequence()
            ->push(['reviews' => [['reviewId' => 'r1']], 'nextPageToken' => 'page-2'])
            ->push(['reviews' => [['reviewId' => 'r2']]]);

        $reviews = $this->factory()->reviews($this->account())->reviews('accounts/999', 'locations/1');

        $this->assertSame(['r1', 'r2'], array_column($reviews, 'reviewId'));
    }

    public function test_an_expired_access_token_is_renewed_before_the_call(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3600]),
            'mybusiness.googleapis.com/*' => Http::response(['reviews' => []]),
        ]);

        $account = $this->account(['token_expires_at' => now()->subMinute()]);

        $this->factory()->reviews($account)->reviews('accounts/999', 'locations/1');

        $this->assertSame('ya29.fresh', $account->fresh()->accessToken());
        $this->assertTrue($account->fresh()->token_expires_at->isFuture());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ya29.fresh'));
    }

    public function test_a_rotated_refresh_token_replaces_the_one_stored(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'ya29.fresh',
                'refresh_token' => '1//rotated',
                'expires_in' => 3600,
            ]),
        ]);

        $account = $this->account();

        app(GBPTokenRefresher::class)->refresh($account);

        $this->assertSame('1//rotated', $account->fresh()->refreshToken());
    }

    public function test_a_four_oh_one_marks_the_connection_expired_and_raises_an_alert(): void
    {
        Http::fake([
            'mybusiness.googleapis.com/*' => Http::response(['error' => ['message' => 'Invalid Credentials']], 401),
        ]);

        $account = $this->account();

        try {
            $this->factory()->reviews($account)->reviews('accounts/999', 'locations/1');
            $this->fail('A 401 should have been reported as an authentication failure.');
        } catch (GBPAuthenticationException $e) {
            // expected
        }

        $this->assertSame(GbpTokenStatus::Expired, $account->fresh()->token_status);

        $alert = Alert::acrossTenants()->where('type', AlertType::GbpTokenExpired)->first();

        $this->assertNotNull($alert);
        $this->assertSame($this->organization->id, $alert->organization_id);
        $this->assertSame($this->location->id, $alert->location_id);
        $this->assertFalse($alert->is_read);
    }

    public function test_the_reconnect_alert_is_raised_once_rather_than_on_every_call(): void
    {
        Http::fake(['mybusiness.googleapis.com/*' => Http::response([], 401)]);

        $account = $this->account();

        foreach (range(1, 3) as $ignored) {
            try {
                $this->factory()->reviews($account->fresh())->reviews('accounts/999', 'locations/1');
            } catch (GBPAuthenticationException $e) {
                // expected
            }
        }

        $this->assertSame(1, Alert::acrossTenants()->where('type', AlertType::GbpTokenExpired)->count());
    }

    public function test_an_invalid_grant_marks_the_connection_revoked(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $account = $this->account(['token_expires_at' => now()->subMinute()]);

        $this->expectException(GBPAuthenticationException::class);

        try {
            app(GBPTokenRefresher::class)->refresh($account);
        } finally {
            $this->assertSame(GbpTokenStatus::Revoked, $account->fresh()->token_status);
        }
    }

    public function test_a_refresh_that_fails_for_another_reason_leaves_the_connection_recoverable(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'server_error'], 500)]);

        $account = $this->account();

        try {
            app(GBPTokenRefresher::class)->refresh($account);
        } catch (GBPAuthenticationException $e) {
            // expected
        }

        $this->assertSame(GbpTokenStatus::Expired, $account->fresh()->token_status);
    }

    public function test_a_connection_without_a_refresh_token_cannot_be_renewed(): void
    {
        $account = $this->account(['refresh_token_encrypted' => null]);

        $this->expectException(GBPAuthenticationException::class);

        app(GBPTokenRefresher::class)->refresh($account);
    }

    public function test_an_ordinary_failure_is_not_treated_as_a_lost_connection(): void
    {
        Http::fake([
            'mybusiness.googleapis.com/*' => Http::response(['error' => ['message' => 'Backend error']], 500),
        ]);

        $account = $this->account();

        try {
            $this->factory()->reviews($account)->reviews('accounts/999', 'locations/1');
            $this->fail('A 500 should have been reported as a failure.');
        } catch (GBPAuthenticationException $e) {
            $this->fail('A 500 is not an authentication failure.');
        } catch (GBPException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $this->assertSame(GbpTokenStatus::Active, $account->fresh()->token_status);
        $this->assertSame(0, Alert::acrossTenants()->count());
    }

    public function test_a_connection_already_marked_unusable_is_refused_without_calling_google(): void
    {
        Http::fake();

        $account = $this->account(['token_status' => GbpTokenStatus::Revoked]);

        $this->expectException(GBPAuthenticationException::class);

        try {
            $this->factory()->reviews($account)->reviews('accounts/999', 'locations/1');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_factory_refuses_a_store_front_that_is_not_connected(): void
    {
        $this->expectException(GBPAuthenticationException::class);

        $this->factory()->connectionFor($this->location);
    }

    public function test_the_business_info_client_derives_the_update_mask_from_what_it_is_given(): void
    {
        Http::fake(['mybusinessbusinessinformation.googleapis.com/*' => Http::response(['name' => 'locations/1'])]);

        $this->factory()->businessInfo($this->account())->updateLocation('locations/1', [
            'title' => '新しい店名',
            'websiteUri' => 'https://example.com',
        ]);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('updateMask=title,websiteUri', urldecode($request->url()));

            return true;
        });
    }

    public function test_a_local_post_leaves_out_the_parts_it_was_not_given(): void
    {
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['name' => 'localPosts/1'])]);

        $this->factory()->localPosts($this->account())->create('accounts/999', 'locations/1', '本日は営業しています');

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('本日は営業しています', $body['summary']);
            $this->assertArrayNotHasKey('media', $body);
            $this->assertArrayNotHasKey('callToAction', $body);

            return true;
        });
    }
}
