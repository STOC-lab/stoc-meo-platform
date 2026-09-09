import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { axisProps, chartColors, tooltipStyle } from '@/components/charts/chart-theme';

export interface RankSeries {
    keyword: string;
    points: { date: string; rank: number | null }[];
}

const palette = [chartColors.primary, chartColors.secondary, chartColors.good, chartColors.fair, chartColors.poor];

/**
 * Rank over time for one or more keywords.
 *
 * The y axis is reversed, because first place belongs at the top: a line that
 * climbs should mean the shop is doing better. A check that found nothing is a
 * gap in the line rather than a zero, which would draw as a spike to the top.
 */
export function RankHistoryChart({ series, height = 260 }: { series: RankSeries[]; height?: number }) {
    const dates = Array.from(new Set(series.flatMap((entry) => entry.points.map((point) => point.date)))).sort();

    const data = dates.map((date) => {
        const row: Record<string, string | number | null> = { date: date.slice(5) };

        for (const entry of series) {
            row[entry.keyword] = entry.points.find((point) => point.date === date)?.rank ?? null;
        }

        return row;
    });

    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -20 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={axisProps.stroke} vertical={false} />
                <XAxis dataKey="date" {...axisProps} />
                <YAxis reversed domain={[1, 'dataMax']} allowDecimals={false} {...axisProps} />
                <Tooltip {...tooltipStyle} formatter={(value) => [value === null ? '圏外' : `${value}位`, '']} />
                {series.length > 1 ? <Legend wrapperStyle={{ fontSize: '0.75rem' }} /> : null}
                {series.map((entry, index) => (
                    <Line
                        key={entry.keyword}
                        type="monotone"
                        dataKey={entry.keyword}
                        stroke={palette[index % palette.length]}
                        strokeWidth={2}
                        dot={false}
                        // A gap means the store front did not appear at all;
                        // joining across it would invent a rank it never held.
                        connectNulls={false}
                    />
                ))}
            </LineChart>
        </ResponsiveContainer>
    );
}
