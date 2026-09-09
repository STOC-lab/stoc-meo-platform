<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKeywordRequest;
use App\Http\Requests\UpdateKeywordRequest;
use App\Jobs\FetchDailyRankingsJob;
use App\Models\Keyword;
use App\Models\Location;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The search terms tracked for one store front.
 *
 * The keyword is resolved through the store front by the route's scoped
 * bindings, but the store front itself is the root of the URL and is bound
 * before the tenant middleware has run, so every action checks that it belongs
 * to the active organization before touching it.
 */
class KeywordController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    public function index(Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', Keyword::class);

        $keywords = $location->keywords()
            ->with('latestRankingResult')
            ->orderBy('keyword')
            ->get();

        return response()->json([
            'keywords' => $keywords->map(fn (Keyword $keyword) => $this->present($keyword))->all(),
            'allowance' => $this->allowance($location),
        ]);
    }

    public function show(Location $location, Keyword $keyword): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('view', $keyword);

        return response()->json([
            'keyword' => $this->present($keyword->load('latestRankingResult')),
        ]);
    }

    public function store(StoreKeywordRequest $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('create', Keyword::class);
        $this->guardKeywordAllowance($location);

        $keyword = $location->keywords()->create($request->validated());

        return response()->json([
            'keyword' => $this->present($keyword),
            'allowance' => $this->allowance($location),
        ], 201);
    }

    public function update(UpdateKeywordRequest $request, Location $location, Keyword $keyword): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('update', $keyword);

        $keyword->update($request->validated());

        return response()->json([
            'keyword' => $this->present($keyword->load('latestRankingResult')),
        ]);
    }

    /**
     * Delete the keyword and the history behind it, giving the allowance back.
     */
    public function destroy(Location $location, Keyword $keyword): Response
    {
        $this->authorizeLocation($location);
        $this->authorize('delete', $keyword);

        $keyword->rankingResults()->delete();
        $keyword->delete();

        return response()->noContent();
    }

    /**
     * Check one keyword's rank now rather than waiting for tonight's sweep.
     *
     * The same job the sweep uses does the work, so a manual check and a
     * scheduled one produce the same kind of row and cannot drift apart.
     */
    public function check(Location $location, Keyword $keyword): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('update', $keyword);

        FetchDailyRankingsJob::dispatch($keyword);

        return response()->json([
            'message' => '順位の取得を開始しました。',
            'keyword' => $this->present($keyword),
        ], 202);
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
     * The plan caps how many keywords a store front tracks. Pausing one keeps
     * it counted; deleting it is what frees the slot.
     *
     * @throws QuotaExceededException
     */
    protected function guardKeywordAllowance(Location $location): void
    {
        $limit = Keyword::allowanceFor($location);

        if ($limit === null) {
            return;
        }

        $used = Keyword::countFor($location);

        if ($used >= $limit) {
            throw QuotaExceededException::for(Feature::RankingKeywordLimit, $limit, $used);
        }
    }

    /**
     * @return array<string, int|null>
     */
    protected function allowance(Location $location): array
    {
        return [
            'limit' => Keyword::allowanceFor($location),
            'used' => Keyword::countFor($location),
            'remaining' => Keyword::remainingAllowanceFor($location),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Keyword $keyword): array
    {
        $latest = $keyword->latestRankingResult;

        return [
            'id' => $keyword->id,
            'keyword' => $keyword->keyword,
            'is_active' => $keyword->is_active,
            'latest_result' => $latest === null ? null : [
                'rank' => $latest->rank,
                'ranked' => $latest->isRanked(),
                'search_url' => $latest->search_url,
                'provider' => $latest->provider,
                'checked_at' => $latest->checked_at->toIso8601String(),
            ],
            'created_at' => $keyword->created_at?->toIso8601String(),
            'updated_at' => $keyword->updated_at?->toIso8601String(),
        ];
    }
}
