import { QueryClient } from '@tanstack/react-query';

/**
 * The shared query client.
 *
 * Most of what the API returns is refreshed by a nightly sweep rather than by
 * the second, so data stays fresh for a minute and is not refetched merely
 * because a window regained focus. A 401 has already been turned into a
 * redirect by the axios interceptor, so retrying one would only delay it.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 60_000,
            refetchOnWindowFocus: false,
            retry: (failureCount, error) => {
                const status = (error as { response?: { status?: number } })?.response?.status;

                if (status !== undefined && status >= 400 && status < 500) {
                    return false;
                }

                return failureCount < 2;
            },
        },
    },
});

/**
 * Query keys, kept in one place so an invalidation cannot miss a screen that
 * reads the same data under a different name.
 */
export const keys = {
    profile: ['profile'] as const,
    locations: (organizationId: number | null) => ['locations', organizationId] as const,
    brands: (organizationId: number | null) => ['brands', organizationId] as const,
    members: (organizationId: number | null) => ['members', organizationId] as const,
    keywords: (locationId: number | null) => ['keywords', locationId] as const,
    rankingHistory: (locationId: number | null, keywordId: number | null) =>
        ['ranking-history', locationId, keywordId] as const,
    heatmaps: (locationId: number | null) => ['heatmaps', locationId] as const,
    heatmap: (locationId: number | null, runId: number | null) => ['heatmap', locationId, runId] as const,
    reviews: (locationId: number | null) => ['reviews', locationId] as const,
    gbpPosts: (locationId: number | null) => ['gbp-posts', locationId] as const,
    campaigns: (locationId: number | null) => ['campaigns', locationId] as const,
    meoScore: (locationId: number | null) => ['meo-score', locationId] as const,
    analyses: (locationId: number | null) => ['analyses', locationId] as const,
    proposals: (locationId: number | null) => ['proposals', locationId] as const,
    alerts: (locationId: number | null) => ['alerts', locationId] as const,
    competitors: (locationId: number | null) => ['competitors', locationId] as const,
    gbpConnection: (locationId: number | null) => ['gbp-connection', locationId] as const,
    reports: (locationId: number | null) => ['reports', locationId] as const,
};
