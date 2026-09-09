import { useState } from 'react';
import { Pause, Play, Plus, RefreshCw, Trash2 } from 'lucide-react';

import { RankHistoryChart } from '@/components/charts/lazy';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/common/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrentLocation } from '@/hooks/use-locations';
import {
    useCheckKeyword,
    useCreateKeyword,
    useDeleteKeyword,
    useKeywords,
    useRankHistories,
    useToggleKeyword,
} from '@/hooks/use-rankings';
import { errorMessage } from '@/lib/api';
import { formatDateTime, formatRank } from '@/lib/format';
import type { Keyword } from '@/types/api';

export default function Rankings() {
    const { location, isPending: locationsPending } = useCurrentLocation();
    const locationId = location?.id ?? null;

    const keywords = useKeywords(locationId);
    const createKeyword = useCreateKeyword(locationId);
    const deleteKeyword = useDeleteKeyword(locationId);
    const toggleKeyword = useToggleKeyword(locationId);
    const checkKeyword = useCheckKeyword(locationId);

    const [dialogOpen, setDialogOpen] = useState(false);
    const [newKeyword, setNewKeyword] = useState('');
    const [days, setDays] = useState(30);

    if (locationsPending) {
        return <LoadingState />;
    }

    if (location === null) {
        return (
            <div className="flex flex-col gap-6">
                <PageHeader title="順位管理" />
                <EmptyState title="店舗が登録されていません" description="設定から店舗を追加してください。" />
            </div>
        );
    }

    const allowance = keywords.data?.allowance;
    const atLimit = allowance?.remaining !== null && allowance?.remaining !== undefined && allowance.remaining <= 0;

    const tracked = keywords.data?.keywords ?? [];

    async function submit(event: React.FormEvent) {
        event.preventDefault();

        if (newKeyword.trim() === '') {
            return;
        }

        try {
            await createKeyword.mutateAsync(newKeyword.trim());
            setNewKeyword('');
            setDialogOpen(false);
        } catch {
            // The dialog stays open and shows the reason below.
        }
    }

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="順位管理"
                description={`${location.name}${
                    allowance
                        ? ` ・ ${allowance.used} / ${allowance.limit ?? '無制限'} キーワード`
                        : ''
                }`}
                action={
                    <Button onClick={() => setDialogOpen(true)} disabled={atLimit}>
                        <Plus aria-hidden="true" />
                        キーワードを追加
                    </Button>
                }
            />

            {atLimit ? (
                <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700">
                    ご利用中のプランのキーワード上限に達しています。追加するには不要なキーワードを削除するか、プランをアップグレードしてください。
                </p>
            ) : null}

            <RankTrendCard
                locationId={locationId}
                keywords={tracked.filter((keyword) => keyword.latest_result !== null).slice(0, 5)}
                days={days}
                onDaysChange={setDays}
            />

            <Card>
                <CardHeader>
                    <CardTitle>計測中のキーワード</CardTitle>
                    <CardDescription>毎日 2:00 に自動計測されます</CardDescription>
                </CardHeader>
                <CardContent>
                    {keywords.isPending ? (
                        <LoadingState />
                    ) : keywords.isError ? (
                        <ErrorState error={keywords.error} />
                    ) : tracked.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 py-10 text-center">
                            <p className="text-sm text-muted-foreground">まだキーワードがありません。</p>
                            <Button size="sm" onClick={() => setDialogOpen(true)}>
                                <Plus aria-hidden="true" />
                                最初のキーワードを追加
                            </Button>
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>キーワード</TableHead>
                                    <TableHead className="w-24">現在順位</TableHead>
                                    <TableHead className="w-44">最終計測</TableHead>
                                    <TableHead className="w-24">状態</TableHead>
                                    <TableHead className="w-40 text-right">操作</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tracked.map((keyword) => (
                                    <TableRow key={keyword.id}>
                                        <TableCell className="font-medium">{keyword.keyword}</TableCell>
                                        <TableCell className="tabular-nums">
                                            {keyword.latest_result === null ? (
                                                <span className="text-muted-foreground">未計測</span>
                                            ) : keyword.latest_result.ranked ? (
                                                formatRank(keyword.latest_result.rank)
                                            ) : (
                                                <Badge variant="secondary">圏外</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDateTime(keyword.latest_result?.checked_at ?? null)}
                                        </TableCell>
                                        <TableCell>
                                            {keyword.is_active ? (
                                                <Badge variant="success">計測中</Badge>
                                            ) : (
                                                <Badge variant="secondary">停止中</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`${keyword.keyword} を今すぐ計測`}
                                                    disabled={checkKeyword.isPending}
                                                    onClick={() => checkKeyword.mutate(keyword.id)}
                                                >
                                                    <RefreshCw aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={
                                                        keyword.is_active
                                                            ? `${keyword.keyword} の計測を停止`
                                                            : `${keyword.keyword} の計測を再開`
                                                    }
                                                    onClick={() =>
                                                        toggleKeyword.mutate({
                                                            id: keyword.id,
                                                            isActive: !keyword.is_active,
                                                        })
                                                    }
                                                >
                                                    {keyword.is_active ? (
                                                        <Pause aria-hidden="true" />
                                                    ) : (
                                                        <Play aria-hidden="true" />
                                                    )}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`${keyword.keyword} を削除`}
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                `「${keyword.keyword}」と計測履歴を削除します。よろしいですか？`,
                                                            )
                                                        ) {
                                                            deleteKeyword.mutate(keyword.id);
                                                        }
                                                    }}
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    {checkKeyword.isSuccess ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            計測を開始しました。結果は数分後に反映されます。
                        </p>
                    ) : null}
                    {checkKeyword.isError ? <ErrorState error={checkKeyword.error} className="mt-3" /> : null}
                    {deleteKeyword.isError ? <ErrorState error={deleteKeyword.error} className="mt-3" /> : null}
                </CardContent>
            </Card>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>キーワードを追加</DialogTitle>
                            <DialogDescription>
                                実際にお客様が検索する言葉を入力してください（例: 渋谷 カフェ）。
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="keyword">キーワード</Label>
                            <Input
                                id="keyword"
                                value={newKeyword}
                                onChange={(event) => setNewKeyword(event.target.value)}
                                placeholder="渋谷 カフェ"
                                autoFocus
                                required
                            />
                        </div>

                        {createKeyword.isError ? (
                            <p className="text-sm text-destructive">{errorMessage(createKeyword.error)}</p>
                        ) : null}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                キャンセル
                            </Button>
                            <Button type="submit" disabled={createKeyword.isPending}>
                                追加
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/**
 * Rank over time, one line per keyword, from the history endpoint.
 *
 * Each keyword is its own request, so a chart of five keywords is five small
 * queries rather than one large one — and one failing leaves the others drawn.
 */
function RankTrendCard({
    locationId,
    keywords,
    days,
    onDaysChange,
}: {
    locationId: number | null;
    keywords: Keyword[];
    days: number;
    onDaysChange: (days: number) => void;
}) {
    const histories = useRankHistories(
        locationId,
        keywords.map((keyword) => keyword.id),
        days,
    );

    if (keywords.length === 0) {
        return null;
    }

    const loading = histories.some((query) => query.isPending);

    const series = keywords
        .map((keyword, index) => ({
            keyword: keyword.keyword,
            points: histories[index]?.data ?? [],
        }))
        .filter((entry) => entry.points.length > 0);

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <CardTitle>順位推移</CardTitle>
                        <CardDescription>計測中キーワードのうち上位5件</CardDescription>
                    </div>
                    <Select value={String(days)} onValueChange={(value) => onDaysChange(Number(value))}>
                        <SelectTrigger className="w-28">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="7">7日</SelectItem>
                            <SelectItem value="30">30日</SelectItem>
                            <SelectItem value="90">90日</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <LoadingState />
                ) : series.length === 0 ? (
                    <p className="py-12 text-center text-sm text-muted-foreground">
                        この期間の計測結果がまだありません。毎日 2:00 に自動計測されます。
                    </p>
                ) : (
                    <RankHistoryChart series={series} />
                )}
            </CardContent>
        </Card>
    );
}
