<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\Location;
use App\Services\Ranking\DataForSEOProvider;
use App\Services\Ranking\FallbackProvider;
use App\Services\Ranking\RankProviderException;
use App\Services\Ranking\RankProviderRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RankProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function keyword(array $locationAttributes = []): Keyword
    {
        $location = Location::factory()->create($locationAttributes);

        return Keyword::factory()->forLocation($location)->create(['keyword' => '渋谷 カフェ']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function provider(array $overrides = []): DataForSEOProvider
    {
        return new DataForSEOProvider(app(HttpFactory::class), array_merge([
            'login' => 'login@example.com',
            'password' => 'secret',
            'sandbox' => true,
            'base_url' => 'https://api.dataforseo.com',
            'sandbox_url' => 'https://sandbox.dataforseo.com',
            'location_name' => 'Japan',
            'language_code' => 'ja',
            'depth' => 100,
            'timeout' => 30,
        ], $overrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function serpResponse(array $items, string $checkUrl = 'https://www.google.com/maps?q=x'): array
    {
        return [
            'status_code' => 20000,
            'tasks' => [[
                'status_code' => 20000,
                'result' => [[
                    'check_url' => $checkUrl,
                    'items' => $items,
                ]],
            ]],
        ];
    }

    public function test_it_calls_the_sandbox_host_while_sandbox_mode_is_on(): void
    {
        Http::fake(['sandbox.dataforseo.com/*' => Http::response($this->serpResponse([]))]);

        $this->provider()->fetch($this->keyword());

        Http::assertSent(fn ($request) => str_starts_with(
            $request->url(),
            'https://sandbox.dataforseo.com/v3/serp/google/maps/live/advanced',
        ));
    }

    public function test_it_calls_the_live_host_once_sandbox_mode_is_off(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response($this->serpResponse([]))]);

        $this->provider(['sandbox' => false])->fetch($this->keyword());

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.dataforseo.com/'));
    }

    public function test_the_request_asks_for_the_keyword_without_rectangle_geometry(): void
    {
        Http::fake(['*' => Http::response($this->serpResponse([]))]);

        $this->provider()->fetch($this->keyword());

        Http::assertSent(function ($request) {
            $task = $request->data()[0];

            return $task['keyword'] === '渋谷 カフェ'
                && $task['calculate_rectangles'] === false
                && $task['language_code'] === 'ja'
                && $task['location_name'] === 'Japan';
        });
    }

    public function test_it_reads_the_rank_of_the_store_front_matched_by_business_profile(): void
    {
        Http::fake(['*' => Http::response($this->serpResponse([
            ['rank_absolute' => 1, 'title' => 'よその店', 'place_id' => 'other'],
            ['rank_absolute' => 4, 'title' => '別名で登録された店', 'place_id' => '1234567890'],
        ], 'https://www.google.com/maps?q=shibuya'))]);

        $result = $this->provider()->fetch($this->keyword([
            'gbp_location_id' => 'locations/1234567890',
        ]));

        $this->assertSame(4, $result->rank);
        $this->assertTrue($result->isRanked());
        $this->assertSame('dataforseo', $result->provider);
        $this->assertSame('https://www.google.com/maps?q=shibuya', $result->searchUrl);
    }

    public function test_it_falls_back_to_the_store_front_name_when_there_is_no_profile_id(): void
    {
        Http::fake(['*' => Http::response($this->serpResponse([
            ['rank_absolute' => 2, 'title' => 'あかね 珈琲'],
        ]))]);

        $result = $this->provider()->fetch($this->keyword([
            'name' => 'あかね珈琲',
            'gbp_location_id' => null,
        ]));

        $this->assertSame(2, $result->rank);
    }

    public function test_a_store_front_that_does_not_appear_is_a_successful_unranked_answer(): void
    {
        Http::fake(['*' => Http::response($this->serpResponse([
            ['rank_absolute' => 1, 'title' => 'よその店', 'place_id' => 'other'],
        ]))]);

        $result = $this->provider()->fetch($this->keyword(['gbp_location_id' => 'locations/1234567890']));

        $this->assertNull($result->rank);
        $this->assertFalse($result->isRanked());
        $this->assertSame('dataforseo', $result->provider);
    }

    public function test_an_http_failure_is_reported_as_a_provider_failure(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectException(RankProviderException::class);

        $this->provider()->fetch($this->keyword());
    }

    public function test_an_error_status_in_the_body_is_reported_as_a_provider_failure(): void
    {
        Http::fake(['*' => Http::response(['status_code' => 40501, 'status_message' => 'Invalid Field'])]);

        $this->expectException(RankProviderException::class);

        $this->provider()->fetch($this->keyword());
    }

    public function test_a_failed_task_is_reported_as_a_provider_failure(): void
    {
        Http::fake(['*' => Http::response([
            'status_code' => 20000,
            'tasks' => [['status_code' => 40401, 'status_message' => 'Not Found']],
        ])]);

        $this->expectException(RankProviderException::class);

        $this->provider()->fetch($this->keyword());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function transientStatuses(): array
    {
        return [
            'the concurrency throttle, which answers as 401' => [401],
            'a request timeout' => [408],
            'a rate limit' => [429],
            'an error at the far end' => [500],
            'the service being unavailable' => [503],
        ];
    }

    #[DataProvider('transientStatuses')]
    public function test_a_momentary_http_status_is_reported_as_transient(int $status): void
    {
        Http::fake(['*' => Http::response('', $status)]);

        try {
            $this->provider()->fetch($this->keyword());
            $this->fail('The provider was expected to fail.');
        } catch (RankProviderException $e) {
            $this->assertTrue($e->isTransient(), "HTTP {$status} should be worth retrying.");
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function permanentStatuses(): array
    {
        return [
            'a malformed request' => [400],
            'a forbidden request' => [403],
            'an endpoint that is not there' => [404],
        ];
    }

    #[DataProvider('permanentStatuses')]
    public function test_a_rejected_request_is_reported_as_permanent(int $status): void
    {
        Http::fake(['*' => Http::response('', $status)]);

        try {
            $this->provider()->fetch($this->keyword());
            $this->fail('The provider was expected to fail.');
        } catch (RankProviderException $e) {
            $this->assertFalse($e->isTransient(), "HTTP {$status} should not be retried.");
        }
    }

    public function test_a_dataforseo_error_status_is_read_as_the_http_status_it_carries(): void
    {
        Http::fake(['*' => Http::response(['status_code' => 50000, 'status_message' => 'Internal Error'])]);

        try {
            $this->provider()->fetch($this->keyword());
            $this->fail('The provider was expected to fail.');
        } catch (RankProviderException $e) {
            $this->assertTrue($e->isTransient(), 'DataForSEO 50000 is its own 500.');
        }
    }

    public function test_a_dataforseo_rejection_status_is_read_as_the_http_status_it_carries(): void
    {
        Http::fake(['*' => Http::response(['status_code' => 40501, 'status_message' => 'Invalid Field'])]);

        try {
            $this->provider()->fetch($this->keyword());
            $this->fail('The provider was expected to fail.');
        } catch (RankProviderException $e) {
            $this->assertFalse($e->isTransient(), 'DataForSEO 40501 is its own 405.');
        }
    }

    public function test_a_task_that_failed_momentarily_is_reported_as_transient(): void
    {
        Http::fake(['*' => Http::response([
            'status_code' => 20000,
            'tasks' => [['status_code' => 50000, 'status_message' => 'Internal Error']],
        ])]);

        try {
            $this->provider()->fetch($this->keyword());
            $this->fail('The provider was expected to fail.');
        } catch (RankProviderException $e) {
            $this->assertTrue($e->isTransient());
        }
    }

    public function test_the_provider_is_unavailable_without_credentials(): void
    {
        $this->assertFalse($this->provider(['login' => null])->isAvailable());
        $this->assertFalse($this->provider(['password' => ''])->isAvailable());
        $this->assertTrue($this->provider()->isAvailable());
    }

    public function test_the_fallback_provider_answers_unranked_with_a_search_to_run_by_hand(): void
    {
        $result = (new FallbackProvider)->fetch($this->keyword());

        $this->assertNull($result->rank);
        $this->assertSame('fallback', $result->provider);
        $this->assertStringContainsString(rawurlencode('渋谷 カフェ'), (string) $result->searchUrl);
    }

    public function test_the_router_skips_a_provider_that_is_not_configured(): void
    {
        Http::fake();

        $router = new RankProviderRouter([
            $this->provider(['login' => null]),
            new FallbackProvider,
        ]);

        $this->assertSame('fallback', $router->fetch($this->keyword())->provider);

        Http::assertNothingSent();
    }

    public function test_the_router_moves_on_when_a_provider_fails(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $router = new RankProviderRouter([$this->provider(), new FallbackProvider]);

        $this->assertSame('fallback', $router->fetch($this->keyword())->provider);
    }

    public function test_the_router_prefers_the_first_provider_that_answers(): void
    {
        Http::fake(['*' => Http::response($this->serpResponse([
            ['rank_absolute' => 3, 'title' => 'あかね珈琲'],
        ]))]);

        $router = new RankProviderRouter([$this->provider(), new FallbackProvider]);
        $result = $router->fetch($this->keyword(['name' => 'あかね珈琲', 'gbp_location_id' => null]));

        $this->assertSame('dataforseo', $result->provider);
        $this->assertSame(3, $result->rank);
    }

    public function test_the_router_gives_up_when_nothing_can_answer(): void
    {
        $router = new RankProviderRouter([$this->provider(['login' => null])]);

        $this->expectException(RankProviderException::class);

        $router->fetch($this->keyword());
    }

    public function test_the_router_raises_a_transient_failure_when_the_caller_will_retry(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $router = new RankProviderRouter([$this->provider(), new FallbackProvider]);

        $this->expectException(RankProviderException::class);

        $router->fetch($this->keyword(), callerWillRetry: true);
    }

    public function test_the_router_still_moves_on_from_a_permanent_failure_when_the_caller_will_retry(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $router = new RankProviderRouter([$this->provider(), new FallbackProvider]);

        $this->assertSame('fallback', $router->fetch($this->keyword(), callerWillRetry: true)->provider);
    }

    public function test_the_router_takes_the_fallback_for_a_transient_failure_once_the_caller_is_out_of_retries(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $router = new RankProviderRouter([$this->provider(), new FallbackProvider]);

        $this->assertSame('fallback', $router->fetch($this->keyword(), callerWillRetry: false)->provider);
    }

    public function test_the_application_wires_dataforseo_ahead_of_the_fallback(): void
    {
        $this->assertSame(
            ['dataforseo', 'fallback'],
            app(RankProviderRouter::class)->providerNames(),
        );
    }
}
