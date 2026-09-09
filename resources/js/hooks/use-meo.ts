import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import type { Alert, Analysis, MeoScore, MeoScorePoint, Proposal, ProposalStatus } from '@/types/api';

interface ScoreResponse {
    score: MeoScore | null;
    history: MeoScorePoint[];
}

export function useMeoScore(locationId: number | null, days = 30) {
    return useQuery({
        queryKey: [...keys.meoScore(locationId), days],
        enabled: locationId !== null,
        queryFn: async (): Promise<ScoreResponse> => {
            const { data } = await api.get<ScoreResponse>(`/locations/${locationId}/meo-score`, {
                params: { days },
            });

            return data;
        },
    });
}

export function useAnalyses(locationId: number | null, type?: 'daily' | 'weekly') {
    return useQuery({
        queryKey: [...keys.analyses(locationId), type ?? 'all'],
        enabled: locationId !== null,
        queryFn: async (): Promise<Analysis[]> => {
            const { data } = await api.get<{ analyses: Analysis[] }>(`/locations/${locationId}/analyses`, {
                params: type ? { type } : undefined,
            });

            return data.analyses;
        },
    });
}

export function useProposals(locationId: number | null) {
    return useQuery({
        queryKey: keys.proposals(locationId),
        enabled: locationId !== null,
        queryFn: async (): Promise<Proposal[]> => {
            const { data } = await api.get<{ proposals: Proposal[] }>(`/locations/${locationId}/proposals`);

            return data.proposals;
        },
    });
}

export function useUpdateProposal(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, status }: { id: number; status: ProposalStatus }) => {
            const { data } = await api.patch(`/locations/${locationId}/proposals/${id}`, { status });

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.proposals(locationId) }),
    });
}

export function useAlerts(locationId: number | null, limit = 10) {
    return useQuery({
        queryKey: [...keys.alerts(locationId), limit],
        enabled: locationId !== null,
        queryFn: async (): Promise<{ alerts: Alert[]; unread_count: number }> => {
            const { data } = await api.get<{ alerts: Alert[]; unread_count: number }>(
                `/locations/${locationId}/alerts`,
                { params: { limit } },
            );

            return data;
        },
    });
}

export function useMarkAlertRead(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, isRead }: { id: number; isRead: boolean }) => {
            const { data } = await api.patch(`/locations/${locationId}/alerts/${id}`, { is_read: isRead });

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.alerts(locationId) }),
    });
}
