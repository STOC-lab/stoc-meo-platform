import { AlertTriangle, Bell, Lightbulb, Sparkles, TrendingUp } from 'lucide-react';
import { Link } from 'react-router';

import { ScoreTrendChart } from '@/components/charts/lazy';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/common/states';
import { ScoreGauge } from '@/components/common/score-gauge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useCurrentLocation } from '@/hooks/use-locations';
import { useAlerts, useAnalyses, useMeoScore, useProposals } from '@/hooks/use-meo';
import { useKeywords } from '@/hooks/use-rankings';
import { formatDate, formatDateTime, formatRank } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useCurrentOrganization } from '@/stores/auth';
import type { Keyword } from '@/types/api';

const componentLabels: Record<string, string> = {
    ranking: '検索順位',
    heatmap: 'エリア分析',
    reviews: '口コミ',
    profile: 'プロフィール',
};

export default function Dashboard() {
    const organization = useCurrentOrganization();
    const { location, isPending: locationsPending } = useCurrentLocation();
    const locationId = location?.id ?? null;

    const score = useMeoScore(locationId);
    const keywords = useKeywords(locationId);
    const alerts = useAlerts(locationId, 5);
    const analyses = useAnalyses(locationId);
    const proposals = useProposals(locationId);

    if (locationsPending) {
        return <LoadingState />;
    }

    if (location === null) {
        return (
            <div className="flex flex-col gap-6">
                <PageHeader title="ダッシュボード" description={organization?.name} />
                <EmptyState
                    title="店舗が登録されていません"
                    description="計測を始めるには、まず店舗を登録してください。"
                    action={
                        <Button asChild>
                            <Link to="/settings">設定で店舗を追加</Link>
                        </Button>
                    }
                />
            </div>
        );
    }

    const latestAnalysis = analyses.data?.[0] ?? null;
    const openProposals = proposals.data?.filter((proposal) => proposal.status === 'new') ?? [];

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="ダッシュボード"
                description={`${location.name}${organization?.plan ? ` ・ ${organization.plan.name}` : ''}`}
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle>MEOスコア</CardTitle>
                        <CardDescription>
                            {score.data?.score
                                ? `${formatDate(score.data.score.calculated_at)} 時点`
                                : '毎日 5:00 に自動計算されます'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col items-center gap-4">
                        {score.isPending ? (
                            <LoadingState label="計算中の結果を確認しています…" />
                        ) : (
                            <>
                                <ScoreGauge score={score.data?.score?.score ?? null} />
                                {score.data?.score ? (
                                    <div className="grid w-full grid-cols-2 gap-2 text-xs">
                                        {Object.entries(score.data.score.breakdown).map(([key, component]) => (
                                            <div
                                                key={key}
                                                className="flex items-center justify-between rounded-md border px-2 py-1.5"
                                            >
                                                <span className="text-muted-foreground">
                                                    {componentLabels[key] ?? key}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'font-medium tabular-nums',
                                                        component.measured ? '' : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {component.measured ? component.score : '計測なし'}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">まだ計測データがありません。</p>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>スコア推移</CardTitle>
                        <CardDescription>直近30日</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {score.isPending ? (
                            <LoadingState />
                        ) : (score.data?.history.length ?? 0) < 2 ? (
                            <p className="py-12 text-center text-sm text-muted-foreground">
                                推移を描くにはあと数日分のデータが必要です。
                            </p>
                        ) : (
                            <ScoreTrendChart points={score.data!.history} />
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <RankingSummaryCard keywords={keywords.data?.keywords ?? []} isPending={keywords.isPending} />

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Bell className="size-4 text-muted-foreground" aria-hidden="true" />
                            <CardTitle>アラート</CardTitle>
                            {(alerts.data?.unread_count ?? 0) > 0 ? (
                                <Badge variant="destructive">{alerts.data!.unread_count}</Badge>
                            ) : null}
                        </div>
                        <CardDescription>順位の急落や連携の問題</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {alerts.isPending ? (
                            <LoadingState />
                        ) : alerts.isError ? (
                            <ErrorState error={alerts.error} />
                        ) : (alerts.data?.alerts.length ?? 0) === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                未対応のアラートはありません。
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {alerts.data!.alerts.map((alert) => (
                                    <li
                                        key={alert.id}
                                        className="flex items-start gap-2 rounded-md border p-3 text-sm"
                                    >
                                        <AlertTriangle
                                            className={cn(
                                                'mt-0.5 size-4 shrink-0',
                                                alert.is_read ? 'text-muted-foreground' : 'text-amber-500',
                                            )}
                                            aria-hidden="true"
                                        />
                                        <div className="min-w-0">
                                            <p className="font-medium">{alert.type_label}</p>
                                            <p className="truncate text-muted-foreground">
                                                {typeof alert.payload.keyword === 'string'
                                                    ? `「${alert.payload.keyword}」 `
                                                    : ''}
                                                {typeof alert.payload.message === 'string'
                                                    ? alert.payload.message
                                                    : typeof alert.payload.drop === 'number'
                                                      ? `${alert.payload.drop}位下落`
                                                      : ''}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDateTime(alert.created_at)}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Sparkles className="size-4 text-muted-foreground" aria-hidden="true" />
                            <CardTitle>AI分析</CardTitle>
                        </div>
                        <CardDescription>
                            {latestAnalysis
                                ? `${latestAnalysis.type_label} ・ ${latestAnalysis.period_start} 〜 ${latestAnalysis.period_end}`
                                : 'プランに含まれる場合に自動生成されます'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {analyses.isPending ? (
                            <LoadingState />
                        ) : latestAnalysis === null ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                まだ分析がありません。
                            </p>
                        ) : (
                            <div className="flex flex-col gap-3 text-sm">
                                <p>{latestAnalysis.summary}</p>
                                {latestAnalysis.highlights.length > 0 ? (
                                    <div>
                                        <p className="text-xs font-medium text-emerald-600">良かった点</p>
                                        <ul className="mt-1 list-inside list-disc text-muted-foreground">
                                            {latestAnalysis.highlights.map((item) => (
                                                <li key={item}>{item}</li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : null}
                                {latestAnalysis.watch.length > 0 ? (
                                    <div>
                                        <p className="text-xs font-medium text-amber-600">注意点</p>
                                        <ul className="mt-1 list-inside list-disc text-muted-foreground">
                                            {latestAnalysis.watch.map((item) => (
                                                <li key={item}>{item}</li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : null}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Lightbulb className="size-4 text-muted-foreground" aria-hidden="true" />
                            <CardTitle>改善提案</CardTitle>
                        </div>
                        <CardDescription>未対応 {openProposals.length} 件</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {proposals.isPending ? (
                            <LoadingState />
                        ) : openProposals.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                未対応の提案はありません。
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {openProposals.slice(0, 4).map((proposal) => (
                                    <li key={proposal.id} className="rounded-md border p-3 text-sm">
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={
                                                    proposal.priority === 'high'
                                                        ? 'destructive'
                                                        : proposal.priority === 'medium'
                                                          ? 'warning'
                                                          : 'secondary'
                                                }
                                            >
                                                {proposal.priority_label}
                                            </Badge>
                                            <span className="font-medium">{proposal.title}</span>
                                        </div>
                                        <p className="mt-1 text-muted-foreground">{proposal.content}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Button asChild variant="outline" size="sm" className="mt-3 w-full">
                            <Link to="/proposals">すべての提案を見る</Link>
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

/**
 * The keywords the shop tracks, with how each one moved since the check before
 * it. Only the latest result is on the list endpoint, so the movement shown is
 * the one the API reports rather than one computed here from partial history.
 */
function RankingSummaryCard({ keywords, isPending }: { keywords: Keyword[]; isPending: boolean }) {
    const tracked = keywords.filter((keyword) => keyword.is_active);

    const series = tracked
        .filter((keyword) => keyword.latest_result !== null)
        .slice(0, 5)
        .map((keyword) => ({
            keyword: keyword.keyword,
            points: [
                {
                    date: keyword.latest_result!.checked_at.slice(0, 10),
                    rank: keyword.latest_result!.rank,
                },
            ],
        }));

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-2">
                    <TrendingUp className="size-4 text-muted-foreground" aria-hidden="true" />
                    <CardTitle>主要キーワード</CardTitle>
                </div>
                <CardDescription>計測中 {tracked.length} 件</CardDescription>
            </CardHeader>
            <CardContent>
                {isPending ? (
                    <LoadingState />
                ) : tracked.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 py-8">
                        <p className="text-sm text-muted-foreground">計測中のキーワードがありません。</p>
                        <Button asChild size="sm">
                            <Link to="/rankings">キーワードを追加</Link>
                        </Button>
                    </div>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {tracked.slice(0, 6).map((keyword) => (
                            <li
                                key={keyword.id}
                                className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm"
                            >
                                <span className="min-w-0 truncate">{keyword.keyword}</span>
                                <span className="flex shrink-0 items-center gap-2">
                                    <span className="font-semibold tabular-nums">
                                        {formatRank(keyword.latest_result?.rank ?? null)}
                                    </span>
                                    {keyword.latest_result === null ? (
                                        <Badge variant="secondary">未計測</Badge>
                                    ) : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {series.length > 0 ? (
                    <Button asChild variant="outline" size="sm" className="mt-3 w-full">
                        <Link to="/rankings">順位の推移を見る</Link>
                    </Button>
                ) : null}
            </CardContent>
        </Card>
    );
}
