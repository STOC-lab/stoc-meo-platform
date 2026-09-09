<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProposalStatus;
use App\Http\Controllers\Controller;
use App\Models\ImprovementProposal;
use App\Models\Location;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The improvement proposals written for one store front.
 *
 * Proposals are produced by the weekly sweep rather than by hand, so there is
 * no create endpoint; what a person does with one is change its status, which
 * is what the update is for.
 */
class ImprovementProposalController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    public function index(Request $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', ImprovementProposal::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(ProposalStatus::class)],
        ]);

        $proposals = ImprovementProposal::query()
            ->where('location_id', $location->getKey())
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->get()
            // Most urgent first, then newest — a list read top-down should
            // start with what matters most.
            ->sortByDesc(fn (ImprovementProposal $proposal) => [
                $proposal->priority->level(),
                $proposal->id,
            ])
            ->values();

        return response()->json([
            'proposals' => $proposals->map(fn (ImprovementProposal $proposal) => $this->present($proposal))->all(),
            'summary' => [
                'total' => $proposals->count(),
                'open' => $proposals->filter(fn ($proposal) => $proposal->status->isOpen())->count(),
            ],
        ]);
    }

    /**
     * Change what the store front has decided about a proposal.
     */
    public function update(Request $request, Location $location, ImprovementProposal $proposal): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('update', $proposal);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProposalStatus::class)],
        ]);

        $proposal->update(['status' => $validated['status']]);

        return response()->json(['proposal' => $this->present($proposal->fresh())]);
    }

    /**
     * Route model binding resolves the store front before the tenant is known,
     * so it can be one from another organization. Answer as though it does not
     * exist rather than confirming it does.
     */
    protected function authorizeLocation(Location $location): void
    {
        abort_unless($location->organization_id === $this->tenancy->id(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(ImprovementProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'category' => $proposal->category->value,
            'category_label' => $proposal->category->label(),
            'title' => $proposal->title,
            'content' => $proposal->content,
            'priority' => $proposal->priority->value,
            'priority_label' => $proposal->priority->label(),
            'status' => $proposal->status->value,
            'status_label' => $proposal->status->label(),
            'score_at_generation' => $proposal->score_at_generation,
            'created_at' => $proposal->created_at?->toIso8601String(),
            'updated_at' => $proposal->updated_at?->toIso8601String(),
        ];
    }
}
