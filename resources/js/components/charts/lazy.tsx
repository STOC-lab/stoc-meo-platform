import { Suspense, lazy } from 'react';
import type { ComponentProps } from 'react';

import { LoadingState } from '@/components/common/states';

/**
 * Recharts is by far the largest thing the app ships, and only two screens
 * draw a chart. Loading it on demand keeps it out of the bundle a person
 * downloads to sign in.
 */
const ScoreTrendChartImpl = lazy(() =>
    import('@/components/charts/score-trend-chart').then((module) => ({ default: module.ScoreTrendChart })),
);

const RankHistoryChartImpl = lazy(() =>
    import('@/components/charts/rank-history-chart').then((module) => ({ default: module.RankHistoryChart })),
);

export function ScoreTrendChart(props: ComponentProps<typeof ScoreTrendChartImpl>) {
    return (
        <Suspense fallback={<LoadingState label="グラフを準備中…" />}>
            <ScoreTrendChartImpl {...props} />
        </Suspense>
    );
}

export function RankHistoryChart(props: ComponentProps<typeof RankHistoryChartImpl>) {
    return (
        <Suspense fallback={<LoadingState label="グラフを準備中…" />}>
            <RankHistoryChartImpl {...props} />
        </Suspense>
    );
}
