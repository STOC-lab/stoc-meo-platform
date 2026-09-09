import { useState } from 'react';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/common/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useCurrentLocation } from '@/hooks/use-locations';
import { useAnalyses, useProposals, useUpdateProposal } from '@/hooks/use-meo';
import { formatDate } from '@/lib/format';
import type { ProposalStatus } from '@/types/api';

const priorityVariants = {
    high: 'destructive',
    medium: 'warning',
    low: 'secondary',
} as const;

export default function Proposals() {
    const { location, isPending: locationsPending } = useCurrentLocation();
    const locationId = location?.id ?? null;

    const proposals = useProposals(locationId);
    const analyses = useAnalyses(locationId);
    const update = useUpdateProposal(locationId);

    const [filter, setFilter] = useState<'open' | 'all'>('open');

    if (locationsPending) {
        return <LoadingState />;
    }

    if (location === null) {
        return (
            <div className="flex flex-col gap-6">
                <PageHeader title="改善提案" />
                <EmptyState title="店舗が登録されていません" description="設定から店舗を追加してください。" />
            </div>
        );
    }

    const all = proposals.data ?? [];
    const shown = filter === 'open' ? all.filter((proposal) => proposal.status === 'new' || proposal.status === 'in_progress') : all;

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="改善提案"
                description={`${location.name} ・ 毎週月曜にAIが計測データから作成します`}
                action={
                    <Select value={filter} onValueChange={(value) => setFilter(value as 'open' | 'all')}>
                        <SelectTrigger className="w-36">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="open">未対応・対応中</SelectItem>
                            <SelectItem value="all">すべて</SelectItem>
                        </SelectContent>
                    </Select>
                }
            />

            {analyses.data && analyses.data.length > 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{analyses.data[0].type_label}</CardTitle>
                        <CardDescription>
                            {analyses.data[0].period_start} 〜 {analyses.data[0].period_end}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-sm">
                        <p>{analyses.data[0].summary}</p>
                    </CardContent>
                </Card>
            ) : null}

            {proposals.isPending ? (
                <LoadingState />
            ) : proposals.isError ? (
                <ErrorState error={proposals.error} />
            ) : shown.length === 0 ? (
                <EmptyState
                    title={all.length === 0 ? '提案がまだありません' : '対応が必要な提案はありません'}
                    description={
                        all.length === 0
                            ? 'MEOスコアが計測されると、翌週から改善提案が作成されます。'
                            : undefined
                    }
                />
            ) : (
                <div className="flex flex-col gap-3">
                    {shown.map((proposal) => (
                        <Card key={proposal.id}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <Badge variant={priorityVariants[proposal.priority]}>
                                            {proposal.priority_label}
                                        </Badge>
                                        <Badge variant="outline">{proposal.category_label}</Badge>
                                        <CardTitle className="text-base">{proposal.title}</CardTitle>
                                    </div>
                                    <CardDescription>{formatDate(proposal.created_at)}</CardDescription>
                                </div>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                <p className="text-sm whitespace-pre-wrap">{proposal.content}</p>

                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        状態: {proposal.status_label}
                                    </span>
                                    <div className="ml-auto flex gap-2">
                                        {(
                                            [
                                                ['in_progress', '対応中にする'],
                                                ['done', '対応済み'],
                                                ['dismissed', '見送る'],
                                            ] as [ProposalStatus, string][]
                                        )
                                            .filter(([status]) => status !== proposal.status)
                                            .map(([status, label]) => (
                                                <Button
                                                    key={status}
                                                    size="sm"
                                                    variant={status === 'done' ? 'default' : 'outline'}
                                                    disabled={update.isPending}
                                                    onClick={() => update.mutate({ id: proposal.id, status })}
                                                >
                                                    {label}
                                                </Button>
                                            ))}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            {update.isError ? <ErrorState error={update.error} /> : null}
        </div>
    );
}
