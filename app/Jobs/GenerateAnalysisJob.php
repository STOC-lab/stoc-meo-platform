<?php

namespace App\Jobs;

use App\Enums\AnalysisType;
use App\Models\Analysis;
use App\Models\Location;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Exceptions\AIRefusalException;
use App\Services\AI\Prompts\AnalysisPrompt;
use App\Services\MEO\AnalysisFigures;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * What the daily and weekly analyses share: gather the period's figures, ask
 * the model to read them back, and store the answer against the period.
 *
 * The two differ only in which window they cover and which plan feature grants
 * them, so those are all the subclasses supply.
 *
 * A period is analysed once per type — re-running overwrites rather than
 * leaving two readings of the same days.
 */
abstract class GenerateAnalysisJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ai';

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public Location $location,
        public ?string $periodEnd = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Which analysis this job writes.
     */
    abstract public function type(): AnalysisType;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        AIProviderFactory $providers,
        AnalysisPrompt $prompt,
        AnalysisFigures $figures,
        Tenancy $tenancy,
    ): void {
        $tenancy->forOrganization($this->location->organization_id, function () use ($providers, $prompt, $figures) {
            $type = $this->type();
            [$start, $end] = $this->window($type);

            $gathered = $figures->gather($this->location, $start, $end);

            try {
                $result = $providers->make()->complete(
                    $prompt->user($this->location, $type, $gathered),
                    $prompt->system($type),
                    [
                        'model' => (string) config('ai.analysis.model', 'fast'),
                        'max_tokens' => (int) config('ai.analysis.max_tokens', 1500),
                    ],
                );
            } catch (AIRefusalException $e) {
                // The same request would be declined again.
                return;
            }

            Analysis::acrossTenants()->updateOrCreate(
                [
                    'location_id' => $this->location->getKey(),
                    'type' => $type,
                    'period_start' => $start->toDateString(),
                ],
                [
                    'organization_id' => $this->location->organization_id,
                    'content' => $this->parse($result->content, $gathered),
                    'period_end' => $end->toDateString(),
                    'model' => $result->model,
                ],
            );
        });
    }

    /**
     * The days the analysis covers, ending on the last whole day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function window(AnalysisType $type): array
    {
        $end = ($this->periodEnd === null
            ? CarbonImmutable::now()->subDay()
            : CarbonImmutable::parse($this->periodEnd))->endOfDay();

        return [$end->subDays($type->windowDays() - 1)->startOfDay(), $end];
    }

    /**
     * Read the model's JSON, keeping only the shape this application stores.
     * The figures are kept alongside it so the reading can be checked against
     * the numbers it was written from.
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    protected function parse(string $content, array $figures): array
    {
        $decoded = json_decode($this->unwrap($content), true);

        if (! is_array($decoded) || ! filled($decoded['summary'] ?? null)) {
            throw AIException::for('claude', 'the analysis was not valid JSON');
        }

        return [
            'summary' => (string) $decoded['summary'],
            'highlights' => $this->strings($decoded['highlights'] ?? []),
            'watch' => $this->strings($decoded['watch'] ?? []),
            'figures' => $figures,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($entry) => is_scalar($entry) ? trim((string) $entry) : '', $value),
            fn (string $entry) => $entry !== '',
        ));
    }

    /**
     * Models sometimes wrap JSON in a fenced code block however plainly they
     * are asked not to.
     */
    protected function unwrap(string $content): string
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        }

        return trim($content);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'ai',
            'analysis:'.$this->type()->value,
            'organization:'.$this->location->organization_id,
            'location:'.$this->location->getKey(),
        ];
    }
}
