import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import type { HeatmapDetail, HeatmapGridSize, HeatmapRun } from '@/types/api';

interface HeatmapsResponse {
    heatmaps: HeatmapRun[];
    allowances: Record<HeatmapGridSize, { limit: number | null; used: number; remaining: number | null }>;
}

export function useHeatmaps(locationId: number | null) {
    return useQuery({
        queryKey: keys.heatmaps(locationId),
        enabled: locationId !== null,
        // A queued run finishes in the background, so an open list catches up
        // on its own rather than waiting for someone to reload.
        refetchInterval: (query) => {
            const data = query.state.data as HeatmapsResponse | undefined;
            const running = data?.heatmaps.some((run) => run.status === 'pending' || run.status === 'running');

            return running ? 10_000 : false;
        },
        queryFn: async (): Promise<HeatmapsResponse> => {
            const { data } = await api.get<HeatmapsResponse>(`/locations/${locationId}/heatmaps`);

            return data;
        },
    });
}

export function useHeatmap(locationId: number | null, runId: number | null) {
    return useQuery({
        queryKey: keys.heatmap(locationId, runId),
        enabled: locationId !== null && runId !== null,
        queryFn: async (): Promise<HeatmapDetail> => {
            const { data } = await api.get<{ heatmap: HeatmapDetail }>(`/locations/${locationId}/heatmaps/${runId}`);

            return data.heatmap;
        },
    });
}

export function useRunHeatmap(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ keywordId, gridSize }: { keywordId: number; gridSize: HeatmapGridSize }) => {
            const { data } = await api.post(`/locations/${locationId}/heatmaps`, {
                keyword_id: keywordId,
                grid_size: gridSize,
            });

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.heatmaps(locationId) }),
    });
}
