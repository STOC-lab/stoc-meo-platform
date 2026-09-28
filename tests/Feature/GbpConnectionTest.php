<?php

namespace Tests\Feature;

use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Choosing the Business Profile account and location after connecting, with
 * Google's APIs faked throughout.
 */
class GbpConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected GbpAccount $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => null,
        ]);
        $this->connection = GbpAccount::factory()->forLocation($this->location)->create([
            'gbp_account_name' => null,
            'access_token_encrypted' => 'ya29.stored',
        ]);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function fakeLocations(): void
    {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/111/locations*' => Http::response([
                'locations' => [
                    [
                        'name' => 'locations/999',
                        'title' => '姫路本店',
                        'storefrontAddress' => [
                            'administrativeArea' => '兵庫県',
                            'locality' => '姫路市',
                            'addressLines' => ['北条梅原町229'],
                        ],
                    ],
                ],
            ]),
        ]);
    }

    public function test_an_administrator_lists_the_accounts_the_google_user_can_act_for(): void
    {
        Http::fake([
            'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response([
                'accounts' => [
                    ['name' => 'accounts/111', 'accountName' => '株式会社エストック', 'type' => 'ORGANIZATION'],
                ],
            ]),
        ]);

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/gbp/accounts?location_id='.$this->location->id)
            ->assertOk()
            ->assertExactJson(['accounts' => [[
                'name' => 'accounts/111',
                'id' => '111',
                'account_name' => '株式会社エストック',
                'type' => 'ORGANIZATION',
            ]]]);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ya29.stored'));
    }

    public function test_an_administrator_lists_the_locations_under_an_account(): void
    {
        $this->fakeLocations();

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/gbp/accounts/111/locations?location_id='.$this->location->id)
            ->assertOk()
            ->assertExactJson(['locations' => [[
                'name' => 'locations/999',
                'title' => '姫路本店',
                'address' => '兵庫県 姫路市 北条梅原町229',
            ]]]);
    }

    public function test_the_chosen_account_and_location_are_stored(): void
    {
        $this->fakeLocations();

        $this->actingAs($this->member('org_admin'))
            ->postJson('/api/v1/gbp/connection/select', [
                'location_id' => $this->location->id,
                'gbp_account_name' => 'accounts/111',
                'gbp_location_id' => 'locations/999',
            ])
            ->assertOk()
            ->assertJsonPath('gbp_account_name', 'accounts/111')
            ->assertJsonPath('gbp_location_id', 'locations/999');

        $this->assertSame('accounts/111', $this->connection->fresh()->gbp_account_name);
        $this->assertSame('locations/999', $this->location->fresh()->gbp_location_id);
    }

    public function test_a_location_google_does_not_list_under_the_account_is_refused(): void
    {
        $this->fakeLocations();

        $this->actingAs($this->member('org_admin'))
            ->postJson('/api/v1/gbp/connection/select', [
                'location_id' => $this->location->id,
                'gbp_account_name' => 'accounts/111',
                'gbp_location_id' => 'locations/123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gbp_location_id');

        $this->assertNull($this->connection->fresh()->gbp_account_name);
        $this->assertNull($this->location->fresh()->gbp_location_id);
    }

    public function test_a_location_already_held_by_another_store_front_is_refused(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/999',
        ]);

        $this->actingAs($this->member('org_admin'))
            ->postJson('/api/v1/gbp/connection/select', [
                'location_id' => $this->location->id,
                'gbp_account_name' => 'accounts/111',
                'gbp_location_id' => 'locations/999',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gbp_location_id');

        Http::assertNothingSent();
    }

    public function test_a_store_manager_cannot_choose_the_profile(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->getJson('/api/v1/gbp/accounts?location_id='.$this->location->id)
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_store_front_of_another_organization_cannot_be_read(): void
    {
        $foreign = Location::factory()->create();

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/gbp/accounts?location_id='.$foreign->id)
            ->assertNotFound();
    }

    public function test_a_store_front_without_a_usable_connection_is_told_to_reconnect(): void
    {
        $this->connection->update(['token_status' => 'revoked']);

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/gbp/accounts?location_id='.$this->location->id)
            ->assertStatus(409)
            ->assertJsonPath('reconnect_required', true);
    }

    public function test_a_refusal_from_google_is_reported_as_a_bad_gateway(): void
    {
        Http::fake(['mybusinessaccountmanagement.googleapis.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/gbp/accounts?location_id='.$this->location->id)
            ->assertStatus(502);
    }
}
