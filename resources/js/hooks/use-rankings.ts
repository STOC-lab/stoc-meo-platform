import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import type { Allowance, Keyword } from '@/types/api';

interface KeywordsResponse {
    keywords: Keyword[];
    allowance: Allowance;
}

export function useKeywords(locationId: number | null) {
    return useQuery({
        queryKey: keys.keywords(locationId),
        enabled: locationId !== null,
        queryFn: async (): Promise<KeywordsResponse> => {
            const { data } = await api.get<KeywordsResponse>(`/locations/${locationId}/keywords`);

            return data;
        },
    });
}

export function useCreateKeyword(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (keyword: string) => {
            const { data } = await api.post(`/locations/${locationId}/keywords`, { keyword });

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.keywords(locationId) }),
    });
}

export function useDeleteKeyword(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (keywordId: number) => {
            await api.delete(`/locations/${locationId}/keywords/${keywordId}`);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.keywords(locationId) }),
    });
}

export function useToggleKeyword(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, isActive }: { id: number; isActive: boolean }) => {
            const { data } = await api.patch(`/locations/${locationId}/keywords/${id}`, { is_active: isActive });

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.keywords(locationId) }),
    });
}

/**
 * Check one keyword now rather than waiting for tonight's sweep. The answer is
 * queued, so the list is refreshed rather than updated in place.
 */
export function useCheckKeyword(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (keywordId: number) => {
            const { data } = await api.post(`/locations/${locationId}/keywords/${keywordId}/check`);

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.keywords(locationId) }),
    });
}
