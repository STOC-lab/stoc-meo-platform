import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import type { Allowance, Campaign, CampaignChannel, GbpPost } from '@/types/api';

interface GbpPostsResponse {
    posts: GbpPost[];
    allowance: Allowance;
}

export function useGbpPosts(locationId: number | null) {
    return useQuery({
        queryKey: keys.gbpPosts(locationId),
        enabled: locationId !== null,
        refetchInterval: (query) => {
            const data = query.state.data as GbpPostsResponse | undefined;
            const working = data?.posts.some((post) => post.status === 'draft' || post.status === 'publishing');

            return working ? 8_000 : false;
        },
        queryFn: async (): Promise<GbpPostsResponse> => {
            const { data } = await api.get<GbpPostsResponse>(`/locations/${locationId}/gbp-posts`);

            return data;
        },
    });
}

export interface NewGbpPost {
    content: string;
    media_url?: string | null;
    cta_type?: string | null;
    cta_url?: string | null;
}

export function useCreateGbpPost(locationId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (post: NewGbpPost) => {
            const { data } = await api.post(`/locations/${locationId}/gbp-posts`, post);

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.gbpPosts(locationId) }),
    });
}

export function useCampaigns(locationId: number | null) {
    return useQuery({
        queryKey: keys.campaigns(locationId),
        enabled: locationId !== null,
        refetchInterval: (query) => {
            const data = query.state.data as Campaign[] | undefined;
            const working = data?.some((campaign) =>
                campaign.posts.some((post) => post.status === 'ai_generating' || post.status === 'publishing'),
            );

            return working ? 8_000 : false;
        },
        queryFn: async (): Promise<Campaign[]> => {
            const { data } = await api.get<{ campaigns: Campaign[] }>(`/locations/${locationId}/campaigns`);

            return data.campaigns;
        },
    });
}

export interface NewCampaign {
    name: string;
    theme?: string | null;
    source_image_path?: string | null;
    campaign_type: 'manual' | 'scheduled' | 'recurring';
    scheduled_at?: string | null;
    channels: CampaignChannel[];
}

function useCampaignMutation<TVariables>(
    locationId: number | null,
    request: (variables: TVariables) => Promise<unknown>,
) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: request,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.campaigns(locationId) }),
    });
}

export function useCreateCampaign(locationId: number | null) {
    return useCampaignMutation<NewCampaign>(locationId, async (campaign) => {
        const { data } = await api.post(`/locations/${locationId}/campaigns`, campaign);

        return data;
    });
}

export function useGenerateCampaign(locationId: number | null) {
    return useCampaignMutation<number>(locationId, async (campaignId) => {
        const { data } = await api.post(`/locations/${locationId}/campaigns/${campaignId}/generate`);

        return data;
    });
}

export function useApproveCampaignPost(locationId: number | null) {
    return useCampaignMutation<{ campaignId: number; postId: number }>(
        locationId,
        async ({ campaignId, postId }) => {
            const { data } = await api.post(
                `/locations/${locationId}/campaigns/${campaignId}/posts/${postId}/approve`,
            );

            return data;
        },
    );
}

export function useCancelCampaign(locationId: number | null) {
    return useCampaignMutation<number>(locationId, async (campaignId) => {
        await api.delete(`/locations/${locationId}/campaigns/${campaignId}`);
    });
}
