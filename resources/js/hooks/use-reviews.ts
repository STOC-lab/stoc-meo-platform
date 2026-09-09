import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import type { Review } from '@/types/api';

interface ReviewsResponse {
    reviews: Review[];
    summary: { total: number; unanswered: number; average_rating: number | null };
}

export function useReviews(locationId: number | null) {
    return useQuery({
        queryKey: keys.reviews(locationId),
        enabled: locationId !== null,
        // A draft being written finishes in the background.
        refetchInterval: (query) => {
            const data = query.state.data as ReviewsResponse | undefined;
            const working = data?.reviews.some(
                (review) => review.ai_reply_status === 'generating' || review.ai_reply_status === 'approved',
            );

            return working ? 8_000 : false;
        },
        queryFn: async (): Promise<ReviewsResponse> => {
            const { data } = await api.get<ReviewsResponse>(`/locations/${locationId}/reviews`);

            return data;
        },
    });
}

function useReviewMutation<TVariables>(
    locationId: number | null,
    request: (variables: TVariables) => Promise<unknown>,
) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: request,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.reviews(locationId) }),
    });
}

/** Send a reply written by a person straight to Google. */
export function useReplyToReview(locationId: number | null) {
    return useReviewMutation<{ id: number; reply: string }>(locationId, async ({ id, reply }) => {
        const { data } = await api.post(`/locations/${locationId}/reviews/${id}/reply`, { reply });

        return data;
    });
}

/** Ask the model for a draft. Nothing reaches Google until it is approved. */
export function useGenerateAiReply(locationId: number | null) {
    return useReviewMutation<number>(locationId, async (reviewId) => {
        const { data } = await api.post(`/locations/${locationId}/reviews/${reviewId}/ai-reply`);

        return data;
    });
}

export function useApproveAiReply(locationId: number | null) {
    return useReviewMutation<number>(locationId, async (reviewId) => {
        const { data } = await api.post(`/locations/${locationId}/reviews/${reviewId}/ai-reply/approve`);

        return data;
    });
}
