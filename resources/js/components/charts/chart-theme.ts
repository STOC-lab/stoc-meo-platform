/**
 * One palette for every chart, so a series means the same thing on whichever
 * screen it appears. The colours are read from the theme's own tokens rather
 * than hard-coded, so charts follow light and dark like the rest of the UI.
 */
export const chartColors = {
    primary: 'var(--color-chart-1, #2563eb)',
    secondary: 'var(--color-chart-2, #7c3aed)',
    good: '#10b981',
    fair: '#f59e0b',
    poor: '#f43f5e',
    muted: 'var(--color-muted-foreground, #64748b)',
};

/** The axis and grid styling every chart shares. */
export const axisProps = {
    stroke: 'var(--color-border, #e2e8f0)',
    tick: { fill: 'var(--color-muted-foreground, #64748b)', fontSize: 11 },
    tickLine: false,
    axisLine: false,
} as const;

export const tooltipStyle = {
    contentStyle: {
        background: 'var(--color-popover, #fff)',
        border: '1px solid var(--color-border, #e2e8f0)',
        borderRadius: '0.5rem',
        fontSize: '0.75rem',
        color: 'var(--color-popover-foreground, #0f172a)',
    },
    labelStyle: { color: 'var(--color-muted-foreground, #64748b)' },
} as const;
