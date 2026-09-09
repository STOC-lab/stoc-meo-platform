import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { axisProps, chartColors, tooltipStyle } from '@/components/charts/chart-theme';
import type { MeoScorePoint } from '@/types/api';

/**
 * The MEO score over time. The y axis is pinned to 0-100 so a flat month does
 * not look like a cliff because the axis rescaled itself.
 */
export function ScoreTrendChart({ points, height = 200 }: { points: MeoScorePoint[]; height?: number }) {
    const data = points.map((point) => ({
        date: point.date.slice(5),
        score: point.score,
    }));

    return (
        <ResponsiveContainer width="100%" height={height}>
            <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -20 }}>
                <defs>
                    <linearGradient id="score-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={chartColors.primary} stopOpacity={0.3} />
                        <stop offset="100%" stopColor={chartColors.primary} stopOpacity={0} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke={axisProps.stroke} vertical={false} />
                <XAxis dataKey="date" {...axisProps} />
                <YAxis domain={[0, 100]} {...axisProps} />
                <Tooltip {...tooltipStyle} formatter={(value) => [`${value}点`, 'スコア']} />
                <Area
                    type="monotone"
                    dataKey="score"
                    stroke={chartColors.primary}
                    strokeWidth={2}
                    fill="url(#score-fill)"
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
