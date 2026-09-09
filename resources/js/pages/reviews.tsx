import { useState } from 'react';
import { Check, Send, Sparkles, Star } from 'lucide-react';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/common/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCurrentLocation } from '@/hooks/use-locations';
import { useApproveAiReply, useGenerateAiReply, useReplyToReview, useReviews } from '@/hooks/use-reviews';
import { errorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Review } from '@/types/api';

type Filter = 'all' | 'unanswered' | 'low';

export default function Reviews() {
    const { location, isPending: locationsPending } = useCurrentLocation();
    const locationId = location?.id ?? null;

    const reviews = useReviews(locationId);
    const [filter, setFilter] = useState<Filter>('all');

    if (locationsPending) {
        return <LoadingState />;
    }

    if (location === null) {
        return (
            <div className="flex flex-col gap-6">
                <PageHeader title="口コミ" />
                <EmptyState title="店舗が登録されていません" description="設定から店舗を追加してください。" />
            </div>
        );
    }

    const all = reviews.data?.reviews ?? [];
    const filtered = all.filter((review) => {
        if (filter === 'unanswered') {
            return !review.answered;
        }

        if (filter === 'low') {
            return review.rating !== null && review.rating <= 3;
        }

        return true;
    });

    const summary = reviews.data?.summary;

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="口コミ"
                description={`${location.name}${
                    summary
                        ? ` ・ ${summary.total} 件 ・ 未返信 ${summary.unanswered} 件 ・ 平均 ${
                              summary.average_rating ?? '—'
                          }`
                        : ''
                }`}
                action={
                    <Select value={filter} onValueChange={(value) => setFilter(value as Filter)}>
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">すべて</SelectItem>
                            <SelectItem value="unanswered">未返信のみ</SelectItem>
                            <SelectItem value="low">低評価（3以下）</SelectItem>
                        </SelectContent>
                    </Select>
                }
            />

            {reviews.isPending ? (
                <LoadingState />
            ) : reviews.isError ? (
                <ErrorState error={reviews.error} />
            ) : filtered.length === 0 ? (
                <EmptyState
                    title={all.length === 0 ? '口コミがありません' : '該当する口コミがありません'}
                    description={
                        all.length === 0
                            ? 'Googleビジネスプロフィールを連携すると、毎日 3:00 に自動で取り込まれます。'
                            : 'フィルターを変更してください。'
                    }
                />
            ) : (
                <div className="flex flex-col gap-4">
                    {filtered.map((review) => (
                        <ReviewCard key={review.id} review={review} locationId={locationId} />
                    ))}
                </div>
            )}
        </div>
    );
}

function Stars({ rating }: { rating: number | null }) {
    if (rating === null) {
        return <span className="text-xs text-muted-foreground">評価なし</span>;
    }

    return (
        <span className="flex items-center gap-0.5" aria-label={`${rating} / 5`}>
            {Array.from({ length: 5 }, (_, index) => (
                <Star
                    key={index}
                    className={cn(
                        'size-4',
                        index < rating ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/40',
                    )}
                    aria-hidden="true"
                />
            ))}
        </span>
    );
}

/**
 * One review, with whichever of the three reply paths applies: write one by
 * hand, ask the model for a draft, or approve the draft it already wrote.
 */
function ReviewCard({ review, locationId }: { review: Review; locationId: number | null }) {
    const generate = useGenerateAiReply(locationId);
    const approve = useApproveAiReply(locationId);
    const reply = useReplyToReview(locationId);

    const [draft, setDraft] = useState('');
    const [writing, setWriting] = useState(false);

    async function submitReply(event: React.FormEvent) {
        event.preventDefault();

        if (draft.trim() === '') {
            return;
        }

        try {
            await reply.mutateAsync({ id: review.id, reply: draft.trim() });
            setDraft('');
            setWriting(false);
        } catch {
            // The form stays open and shows the reason below.
        }
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-3">
                        <Stars rating={review.rating} />
                        <CardTitle className="text-base">{review.author_name ?? '匿名'}</CardTitle>
                    </div>
                    <div className="flex items-center gap-2">
                        {review.answered ? (
                            <Badge variant="success">返信済み</Badge>
                        ) : (
                            <Badge variant="warning">未返信</Badge>
                        )}
                        <CardDescription>{formatDate(review.reviewed_at)}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <p className="text-sm whitespace-pre-wrap">{review.comment ?? '（本文なし）'}</p>

                {review.reply ? (
                    <div className="rounded-md border bg-muted/40 p-3">
                        <p className="text-xs font-medium text-muted-foreground">店舗からの返信</p>
                        <p className="mt-1 text-sm whitespace-pre-wrap">{review.reply}</p>
                    </div>
                ) : null}

                {review.ai_reply && !review.answered ? (
                    <div className="rounded-md border border-primary/30 bg-primary/5 p-3">
                        <div className="flex items-center gap-2">
                            <Sparkles className="size-4 text-primary" aria-hidden="true" />
                            <p className="text-xs font-medium">AIが作成した返信案</p>
                            {review.ai_reply_status_label ? (
                                <Badge variant="outline">{review.ai_reply_status_label}</Badge>
                            ) : null}
                        </div>
                        <p className="mt-2 text-sm whitespace-pre-wrap">{review.ai_reply}</p>
                        {review.ai_reply_awaiting_approval ? (
                            <Button
                                size="sm"
                                className="mt-3"
                                disabled={approve.isPending}
                                onClick={() => approve.mutate(review.id)}
                            >
                                <Check aria-hidden="true" />
                                承認してGoogleに投稿
                            </Button>
                        ) : null}
                    </div>
                ) : null}

                {review.ai_reply_error ? (
                    <p className="text-sm text-destructive">{review.ai_reply_error}</p>
                ) : null}

                {!review.answered ? (
                    <div className="flex flex-wrap gap-2">
                        {!review.ai_reply || review.ai_reply_status === 'failed' ? (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={generate.isPending || review.ai_reply_status === 'generating'}
                                onClick={() => generate.mutate(review.id)}
                            >
                                <Sparkles aria-hidden="true" />
                                {review.ai_reply_status === 'generating' ? '生成中…' : 'AIで返信案を作成'}
                            </Button>
                        ) : null}

                        {!writing ? (
                            <Button variant="ghost" size="sm" onClick={() => setWriting(true)}>
                                自分で返信を書く
                            </Button>
                        ) : null}
                    </div>
                ) : null}

                {generate.isError ? <ErrorState error={generate.error} /> : null}
                {approve.isError ? <ErrorState error={approve.error} /> : null}

                {writing ? (
                    <form onSubmit={submitReply} className="flex flex-col gap-2">
                        <Textarea
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            placeholder="返信を入力してください"
                            rows={4}
                            maxLength={4096}
                            autoFocus
                        />
                        {reply.isError ? (
                            <p className="text-sm text-destructive">{errorMessage(reply.error)}</p>
                        ) : null}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={() => setWriting(false)}>
                                キャンセル
                            </Button>
                            <Button type="submit" size="sm" disabled={reply.isPending}>
                                <Send aria-hidden="true" />
                                Googleに投稿
                            </Button>
                        </div>
                    </form>
                ) : null}
            </CardContent>
        </Card>
    );
}
