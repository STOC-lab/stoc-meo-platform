import { useEffect, useState } from 'react';
import { Play } from 'lucide-react';

import { HeatmapGrid } from '@/components/common/heatmap-grid';
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
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useHeatmap, useHeatmaps, useRunHeatmap } from '@/hooks/use-heatmaps';
import { useCurrentLocation } from '@/hooks/use-locations';
import { useKeywords } from '@/hooks/use-rankings';
import { errorMessage } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import type { HeatmapGridSize, HeatmapStatus } from '@/types/api';

const statusVariants: Record<HeatmapStatus, 'default' | 'secondary' | 'success' | 'destructive'> = {
    pending: 'secondary',
    running: 'default',
    completed: 'success',
    failed: 'destructive',
};

export default function Heatmaps() {
    const { location, isPending: locationsPending } = useCurrentLocation();
    const locationId = location?.id ?? null;

    const heatmaps = useHeatmaps(locationId);
    const keywords = useKeywords(locationId);
    const runHeatmap = useRunHeatmap(locationId);

    const [selectedRunId, setSelectedRunId] = useState<number | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [keywordId, setKeywordId] = useState<string>('');
    const [gridSize, setGridSize] = useState<HeatmapGridSize>('5x5');

    const runs = heatmaps.data?.heatmaps ?? [];
    const firstCompleted = runs.find((run) => run.status === 'completed') ?? null;

    // Follow the newest finished run until someone picks another.
    useEffect(() => {
        if (selectedRunId === null && firstCompleted !== null) {
            setSelectedRunId(firstCompleted.id);
        }
    }, [firstCompleted, selectedRunId]);

    const detail = useHeatmap(locationId, selectedRunId);

    if (locationsPending) {
        return <LoadingState />;
    }

    if (location === null) {
        return (
            <div className="flex flex-col gap-6">
                <PageHeader title="ヒートマップ" />
                <EmptyState title="店舗が登録されていません" description="設定から店舗を追加してください。" />
            </div>
        );
    }

    const allowances = heatmaps.data?.allowances;

    async function submit(event: React.FormEvent) {
        event.preventDefault();

        if (keywordId === '') {
            return;
        }

        try {
            await runHeatmap.mutateAsync({ keywordId: Number(keywordId), gridSize });
            setDialogOpen(false);
        } catch {
            // The dialog stays open and shows the reason below.
        }
    }

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="ヒートマップ"
                description={`${location.name} ・ エリア別の表示順位`}
                action={
                    <Button onClick={() => setDialogOpen(true)} disabled={!location.has_coordinates}>
                        <Play aria-hidden="true" />
                        実行
                    </Button>
                }
            />

            {!location.has_coordinates ? (
                <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700">
                    ヒートマップを実行するには、設定でこの店舗の緯度・経度を登録してください。
                </p>
            ) : null}

            {allowances ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    {(['5x5', '7x7'] as const).map((size) => (
                        <Card key={size}>
                            <CardContent className="flex items-center justify-between py-4">
                                <div>
                                    <p className="text-sm font-medium">{size} グリッド</p>
                                    <p className="text-xs text-muted-foreground">
                                        {size === '5x5' ? '25地点' : '49地点'}
                                    </p>
                                </div>
                                <p className="text-sm tabular-nums text-muted-foreground">
                                    今月 {allowances[size].used} / {allowances[size].limit ?? '無制限'}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-5">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>実行履歴</CardTitle>
                        <CardDescription>行を選ぶとグリッドを表示します</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {heatmaps.isPending ? (
                            <LoadingState />
                        ) : heatmaps.isError ? (
                            <ErrorState error={heatmaps.error} />
                        ) : runs.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                まだ実行されていません。
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>キーワード</TableHead>
                                        <TableHead className="w-20">グリッド</TableHead>
                                        <TableHead className="w-24">状態</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {runs.map((run) => (
                                        <TableRow
                                            key={run.id}
                                            className="cursor-pointer"
                                            data-state={run.id === selectedRunId ? 'selected' : undefined}
                                            onClick={() => setSelectedRunId(run.id)}
                                        >
                                            <TableCell>
                                                <p className="font-medium">{run.keyword ?? '—'}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTime(run.completed_at ?? run.created_at)}
                                                </p>
                                            </TableCell>
                                            <TableCell>{run.grid_size}</TableCell>
                                            <TableCell>
                                                <Badge variant={statusVariants[run.status]}>{run.status_label}</Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card className="lg:col-span-3">
                    <CardHeader>
                        <CardTitle>グリッド</CardTitle>
                        <CardDescription>
                            {detail.data
                                ? `${detail.data.keyword ?? ''} ・ ${detail.data.grid_size} ・ ${
                                      detail.data.points_recorded
                                  } / ${detail.data.point_count} 地点`
                                : '完了した実行を選択してください'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {selectedRunId === null ? (
                            <p className="py-12 text-center text-sm text-muted-foreground">
                                左の履歴から実行を選んでください。
                            </p>
                        ) : detail.isPending ? (
                            <LoadingState />
                        ) : detail.isError ? (
                            <ErrorState error={detail.error} />
                        ) : detail.data!.status === 'failed' ? (
                            <div className="py-8 text-center">
                                <Badge variant="destructive">失敗</Badge>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {detail.data!.failure_reason ?? '実行に失敗しました。'}
                                </p>
                            </div>
                        ) : detail.data!.status !== 'completed' ? (
                            <LoadingState label="取得中です。完了すると自動で表示されます…" />
                        ) : (
                            <HeatmapGrid grid={detail.data!.grid} />
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>ヒートマップを実行</DialogTitle>
                            <DialogDescription>
                                店舗周辺のグリッド各地点から検索し、順位を取得します。
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="heatmap-keyword">キーワード</Label>
                            <Select value={keywordId} onValueChange={setKeywordId}>
                                <SelectTrigger id="heatmap-keyword" className="w-full">
                                    <SelectValue placeholder="キーワードを選択" />
                                </SelectTrigger>
                                <SelectContent>
                                    {(keywords.data?.keywords ?? []).map((keyword) => (
                                        <SelectItem key={keyword.id} value={String(keyword.id)}>
                                            {keyword.keyword}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {(keywords.data?.keywords.length ?? 0) === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    先に「順位」画面でキーワードを追加してください。
                                </p>
                            ) : null}
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="heatmap-grid">グリッドサイズ</Label>
                            <Select value={gridSize} onValueChange={(value) => setGridSize(value as HeatmapGridSize)}>
                                <SelectTrigger id="heatmap-grid" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="5x5">5×5（25地点）</SelectItem>
                                    <SelectItem value="7x7">7×7（49地点）</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {runHeatmap.isError ? (
                            <p className="text-sm text-destructive">{errorMessage(runHeatmap.error)}</p>
                        ) : null}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                キャンセル
                            </Button>
                            <Button type="submit" disabled={runHeatmap.isPending || keywordId === ''}>
                                実行する
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
