<?php

namespace Tests\Feature;

use App\Services\AI\AIProviderFactory;
use App\Services\AI\ClaudeProvider;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Exceptions\AIRefusalException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every model call in this file is faked; nothing here reaches Anthropic.
 */
class ClaudeProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'ai.claude.api_key' => 'sk-ant-test',
            'ai.claude.models' => ['fast' => 'claude-haiku-4-5', 'strong' => 'claude-sonnet-4-6'],
        ]);
    }

    protected function provider(): ClaudeProvider
    {
        return app(ClaudeProvider::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    protected function fakeMessage(array $content, string $stopReason = 'end_turn', array $extra = []): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'model' => 'claude-haiku-4-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
            ...$extra,
        ])]);
    }

    public function test_the_provider_returns_the_text_the_model_wrote(): void
    {
        $this->fakeMessage([['type' => 'text', 'text' => 'ご来店ありがとうございました。']]);

        $result = $this->provider()->complete('口コミ本文', 'あなたは店舗オーナーです');

        $this->assertSame('ご来店ありがとうございました。', $result->content);
        $this->assertSame('claude-haiku-4-5', $result->model);
        $this->assertSame(120, $result->inputTokens);
        $this->assertSame(45, $result->outputTokens);
        $this->assertFalse($result->wasTruncated());
    }

    public function test_the_request_carries_the_key_the_version_and_the_prompt(): void
    {
        $this->fakeMessage([['type' => 'text', 'text' => 'ok']]);

        $this->provider()->complete('本文', 'システム', ['max_tokens' => 600]);

        Http::assertSent(function ($request) {
            $this->assertSame('sk-ant-test', $request->header('x-api-key')[0]);
            $this->assertSame(ClaudeProvider::API_VERSION, $request->header('anthropic-version')[0]);
            $this->assertStringContainsString('/v1/messages', $request->url());

            $body = $request->data();

            $this->assertSame('claude-haiku-4-5', $body['model']);
            $this->assertSame(600, $body['max_tokens']);
            $this->assertSame('システム', $body['system']);
            $this->assertSame([['role' => 'user', 'content' => '本文']], $body['messages']);

            return true;
        });
    }

    public function test_the_strong_model_is_used_when_it_is_asked_for(): void
    {
        $this->fakeMessage([['type' => 'text', 'text' => 'ok']]);

        $this->provider()->complete('本文', null, ['model' => 'strong']);

        Http::assertSent(fn ($request) => $request->data()['model'] === 'claude-sonnet-4-6');
    }

    public function test_an_unknown_model_name_falls_back_to_the_fast_one(): void
    {
        $this->fakeMessage([['type' => 'text', 'text' => 'ok']]);

        $this->provider()->complete('本文', null, ['model' => 'gpt-nonsense']);

        Http::assertSent(fn ($request) => $request->data()['model'] === 'claude-haiku-4-5');
    }

    public function test_text_is_gathered_from_every_block_that_carries_some(): void
    {
        $this->fakeMessage([
            ['type' => 'thinking', 'thinking' => '考え中'],
            ['type' => 'text', 'text' => '前半。'],
            ['type' => 'text', 'text' => '後半。'],
        ]);

        $this->assertSame('前半。後半。', $this->provider()->complete('本文')->content);
    }

    public function test_a_refusal_arrives_as_a_successful_response_and_is_recognised(): void
    {
        // A decline is HTTP 200 with stop_reason "refusal", not an error.
        $this->fakeMessage([], 'refusal', ['stop_details' => ['type' => 'refusal', 'category' => 'cyber']]);

        try {
            $this->provider()->complete('本文');
            $this->fail('A refusal should have been reported.');
        } catch (AIRefusalException $e) {
            $this->assertSame('cyber', $e->category);
        }
    }

    public function test_a_truncated_answer_says_so(): void
    {
        $this->fakeMessage([['type' => 'text', 'text' => '途中で']], 'max_tokens');

        $this->assertTrue($this->provider()->complete('本文')->wasTruncated());
    }

    public function test_an_http_failure_is_reported_as_a_provider_failure(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'error' => ['message' => 'overloaded_error'],
        ], 529)]);

        $this->expectException(AIException::class);

        $this->provider()->complete('本文');
    }

    public function test_a_provider_without_a_key_is_not_available(): void
    {
        config(['ai.claude.api_key' => null]);

        $this->assertFalse($this->provider()->isAvailable());
    }

    public function test_the_factory_hands_out_the_configured_provider(): void
    {
        $factory = app(AIProviderFactory::class);

        $this->assertTrue($factory->isAvailable());
        $this->assertSame('claude', $factory->make()->name());
        $this->assertSame(['claude'], $factory->providerNames());
    }

    public function test_the_factory_refuses_a_provider_that_is_not_configured(): void
    {
        config(['ai.claude.api_key' => null]);

        $this->expectException(AIException::class);

        app(AIProviderFactory::class)->make();
    }

    public function test_the_factory_refuses_a_provider_it_does_not_know(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AIProviderFactory::class)->make('some-other-model');
    }
}
