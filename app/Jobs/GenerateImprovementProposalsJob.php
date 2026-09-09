<?php

namespace App\Jobs;

use App\Enums\ProposalCategory;
use App\Enums\ProposalPriority;
use App\Enums\ProposalStatus;
use App\Models\ImprovementProposal;
use App\Models\Location;
use App\Models\MeoScore;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Exceptions\AIRefusalException;
use App\Services\AI\Prompts\ImprovementProposalPrompt;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks a model what the store front should do next, from its latest score.
 *
 * The score's own working is what the model is given, so the advice is
 * anchored to something measured rather than invented. Proposals still open
 * from last time are passed in as well, so a weekly sweep does not keep
 * repeating itself.
 *
 * The answer is JSON, and anything in it that is not a shape this application
 * recognises is dropped rather than stored — a model's output is untrusted
 * input like any other.
 */
class GenerateImprovementProposalsJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ai';

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public Location $location)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        AIProviderFactory $providers,
        ImprovementProposalPrompt $prompt,
        Tenancy $tenancy,
    ): void {
        $tenancy->forOrganization($this->location->organization_id, function () use ($providers, $prompt) {
            $score = MeoScore::acrossTenants()
                ->where('location_id', $this->location->getKey())
                ->latest('calculated_at')
                ->first();

            // Nothing has been scored yet, so there is nothing to advise on.
            if ($score === null) {
                return;
            }

            $existing = ImprovementProposal::acrossTenants()
                ->where('location_id', $this->location->getKey())
                ->open()
                ->latest('id')
                ->limit(20)
                ->get();

            try {
                $result = $providers->make()->complete(
                    $prompt->user($this->location, $score, $existing),
                    $prompt->system(),
                    [
                        'model' => (string) config('ai.proposals.model', 'strong'),
                        'max_tokens' => (int) config('ai.proposals.max_tokens', 2000),
                    ],
                );
            } catch (AIRefusalException $e) {
                // The same request would be declined again.
                return;
            }

            foreach ($this->parse($result->content) as $proposal) {
                ImprovementProposal::create([
                    'organization_id' => $this->location->organization_id,
                    'location_id' => $this->location->getKey(),
                    ...$proposal,
                    'status' => ProposalStatus::New,
                    'score_at_generation' => $score->score,
                ]);
            }
        });
    }

    /**
     * Read the model's JSON, keeping only the entries that are the shape this
     * application stores.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parse(string $content): array
    {
        $decoded = json_decode($this->unwrap($content), true);

        if (! is_array($decoded)) {
            throw AIException::for('claude', 'the proposals were not valid JSON');
        }

        $proposals = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $category = ProposalCategory::tryFrom((string) ($entry['category'] ?? ''));
            $title = trim((string) ($entry['title'] ?? ''));
            $body = trim((string) ($entry['content'] ?? ''));

            if ($category === null || $title === '' || $body === '') {
                continue;
            }

            $proposals[] = [
                'category' => $category,
                'title' => mb_substr($title, 0, 255),
                'content' => $body,
                'priority' => ProposalPriority::tryFrom((string) ($entry['priority'] ?? ''))
                    ?? ProposalPriority::Medium,
            ];
        }

        return $proposals;
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

    public function failed(?Throwable $exception): void
    {
        // Nothing to close off: a run that produced no proposals simply leaves
        // the store front with the ones it already had.
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'ai',
            'proposals',
            'organization:'.$this->location->organization_id,
            'location:'.$this->location->getKey(),
        ];
    }
}
