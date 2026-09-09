<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\ProposalCategory;
use App\Enums\ProposalPriority;
use App\Enums\ProposalStatus;
use App\Jobs\GenerateImprovementProposalsJob;
use App\Models\ImprovementProposal;
use App\Models\Location;
use App\Models\MeoScore;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Prompts\ImprovementProposalPrompt;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every model call in this file is faked; nothing here reaches Anthropic.
 */
class ImprovementProposalTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'ai.claude.api_key' => 'sk-ant-test',
            'ai.claude.models' => ['fast' => 'claude-haiku-4-5', 'strong' => 'claude-sonnet-4-6'],
        ]);

        $this->organization = $this->organizationOnPlan([
            Feature::AiImprovementProposalsEnabled->value => true,
        ]);

        $this->location = Location::factory()->create(['organization_id' => $this->organization->id]);
    }

    /**
     * @param  array<string, int|bool|null>  $features
     */
    protected function organizationOnPlan(array $features): Organization
    {
        return Organization::factory()
            ->onPlan(Plan::factory()->withFeatures($features)->create())
            ->create();
    }

    protected function member(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create();
        ($organization ?? $this->organization)->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $path = '', ?Location $location = null): string
    {
        return '/api/v1/locations/'.($location ?? $this->location)->id.'/proposals'.$path;
    }

    protected function fakeModel(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 300, 'output_tokens' => 200],
        ])]);
    }

    protected function runJob(): void
    {
        (new GenerateImprovementProposalsJob($this->location))->handle(
            app(AIProviderFactory::class),
            app(ImprovementProposalPrompt::class),
            app(Tenancy::class),
        );
    }

    public function test_the_job_stores_the_proposals_the_model_returned(): void
    {
        MeoScore::factory()->forLocation($this->location)->create(['score' => 62.5]);

        $this->fakeModel(json_encode([
            ['category' => 'reviews', 'priority' => 'high', 'title' => '未返信の口コミに返信する', 'content' => '直近30日で未返信が8件あります。'],
            ['category' => 'profile', 'priority' => 'medium', 'title' => '営業時間を登録する', 'content' => '営業時間が未登録です。'],
        ], JSON_UNESCAPED_UNICODE));

        $this->runJob();

        $proposals = ImprovementProposal::acrossTenants()->get();

        $this->assertCount(2, $proposals);

        $first = $proposals->firstWhere('title', '未返信の口コミに返信する');

        $this->assertSame(ProposalCategory::Reviews, $first->category);
        $this->assertSame(ProposalPriority::High, $first->priority);
        $this->assertSame(ProposalStatus::New, $first->status);
        $this->assertSame(62.5, $first->score_at_generation);
        $this->assertSame($this->organization->id, $first->organization_id);
    }

    public function test_the_prompt_carries_the_scores_working_and_what_was_already_suggested(): void
    {
        MeoScore::factory()->forLocation($this->location)->create(['score' => 62.5]);
        ImprovementProposal::factory()->forLocation($this->location)->create(['title' => '既出の提案']);

        $this->fakeModel('[]');

        $this->runJob();

        Http::assertSent(function ($request) {
            $prompt = $request->data()['messages'][0]['content'];

            $this->assertStringContainsString('62.5', $prompt);
            $this->assertStringContainsString('ranking', $prompt);
            $this->assertStringContainsString('既出の提案', $prompt);
            $this->assertStringContainsString('データにない事実', $request->data()['system']);

            return true;
        });
    }

    public function test_the_stronger_model_is_used_for_advice(): void
    {
        MeoScore::factory()->forLocation($this->location)->create();
        $this->fakeModel('[]');

        $this->runJob();

        Http::assertSent(fn ($request) => $request->data()['model'] === 'claude-sonnet-4-6');
    }

    public function test_entries_the_application_does_not_recognise_are_dropped(): void
    {
        MeoScore::factory()->forLocation($this->location)->create();

        // A model's output is untrusted input: an unknown category, a missing
        // title and a non-object all have to be dropped rather than stored.
        $this->fakeModel(json_encode([
            ['category' => 'astrology', 'priority' => 'high', 'title' => 'x', 'content' => 'y'],
            ['category' => 'reviews', 'priority' => 'high', 'title' => '', 'content' => 'y'],
            ['category' => 'profile', 'priority' => 'nonsense', 'title' => '有効な提案', 'content' => '本文'],
            'not an object',
        ], JSON_UNESCAPED_UNICODE));

        $this->runJob();

        $proposals = ImprovementProposal::acrossTenants()->get();

        $this->assertCount(1, $proposals);
        $this->assertSame('有効な提案', $proposals->first()->title);
        // An unreadable priority falls back rather than failing the entry.
        $this->assertSame(ProposalPriority::Medium, $proposals->first()->priority);
    }

    public function test_json_wrapped_in_a_code_fence_is_still_read(): void
    {
        MeoScore::factory()->forLocation($this->location)->create();

        $this->fakeModel("```json\n".json_encode([
            ['category' => 'ranking', 'priority' => 'low', 'title' => '提案', 'content' => '本文'],
        ], JSON_UNESCAPED_UNICODE)."\n```");

        $this->runJob();

        $this->assertSame(1, ImprovementProposal::acrossTenants()->count());
    }

    public function test_a_store_front_that_has_never_been_scored_is_left_alone(): void
    {
        Http::fake();

        $this->runJob();

        Http::assertNothingSent();
        $this->assertSame(0, ImprovementProposal::acrossTenants()->count());
    }

    public function test_a_model_that_declines_leaves_the_existing_proposals_alone(): void
    {
        MeoScore::factory()->forLocation($this->location)->create();
        ImprovementProposal::factory()->forLocation($this->location)->create();

        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-6',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'other'],
        ])]);

        $this->runJob();

        $this->assertSame(1, ImprovementProposal::acrossTenants()->count());
    }

    public function test_the_job_runs_on_the_ai_queue(): void
    {
        $this->assertSame('ai', (new GenerateImprovementProposalsJob($this->location))->queue);
    }

    public function test_a_member_lists_the_proposals_most_urgent_first(): void
    {
        ImprovementProposal::factory()->forLocation($this->location)
            ->priority(ProposalPriority::Low)->create(['title' => '低']);
        ImprovementProposal::factory()->forLocation($this->location)
            ->priority(ProposalPriority::High)->create(['title' => '高']);
        ImprovementProposal::factory()->forLocation($this->location)
            ->priority(ProposalPriority::Medium)->create(['title' => '中']);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(3, 'proposals')
            ->assertJsonPath('proposals.0.title', '高')
            ->assertJsonPath('proposals.1.title', '中')
            ->assertJsonPath('proposals.2.title', '低')
            ->assertJsonPath('summary.open', 3);
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        ImprovementProposal::factory()->forLocation($this->location)->create();
        ImprovementProposal::factory()->forLocation($this->location)
            ->status(ProposalStatus::Done)->create(['title' => '対応済み']);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('?status=done'))
            ->assertOk()
            ->assertJsonCount(1, 'proposals')
            ->assertJsonPath('proposals.0.title', '対応済み');
    }

    public function test_a_staff_member_marks_a_proposal_done(): void
    {
        $proposal = ImprovementProposal::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('staff'))
            ->patchJson($this->url("/{$proposal->id}"), ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('proposal.status', 'done')
            ->assertJsonPath('proposal.status_label', '対応済み');

        $this->assertSame(ProposalStatus::Done, $proposal->fresh()->status);
    }

    public function test_a_dismissed_proposal_is_kept_rather_than_deleted(): void
    {
        $proposal = ImprovementProposal::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('staff'))
            ->patchJson($this->url("/{$proposal->id}"), ['status' => 'dismissed'])
            ->assertOk();

        // Kept, so the weekly sweep does not suggest it again as though new.
        $this->assertDatabaseHas('improvement_proposals', ['id' => $proposal->id]);
        $this->assertFalse($proposal->fresh()->status->isOpen());
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $proposal = ImprovementProposal::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('staff'))
            ->patchJson($this->url("/{$proposal->id}"), ['status' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_a_viewer_cannot_change_a_proposal(): void
    {
        $proposal = ImprovementProposal::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('viewer'))
            ->patchJson($this->url("/{$proposal->id}"), ['status' => 'done'])
            ->assertForbidden();
    }

    public function test_proposals_of_another_store_front_are_not_listed(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);

        ImprovementProposal::factory()->forLocation($this->location)->create();
        ImprovementProposal::factory()->forLocation($other)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'proposals');
    }

    public function test_a_store_front_of_another_organization_is_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('', $foreign))
            ->assertNotFound();
    }

    public function test_the_weekly_sweep_only_queues_plans_that_include_proposals(): void
    {
        Queue::fake();

        Location::factory()->create([
            'organization_id' => $this->organizationOnPlan([
                Feature::AiImprovementProposalsEnabled->value => false,
            ])->id,
        ]);

        $this->artisan('ai:insights weekly')->assertSuccessful();

        Queue::assertPushed(GenerateImprovementProposalsJob::class, 1);
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
