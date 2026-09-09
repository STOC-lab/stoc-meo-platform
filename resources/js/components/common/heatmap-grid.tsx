import { cn } from '@/lib/utils';

/**
 * A heatmap run drawn as its grid.
 *
 * The colour bands are the ones a shop owner cares about — the top three, the
 * top ten, the rest, and not present at all — rather than a smooth gradient
 * that makes fourth and fourteenth look alike. The number is printed in every
 * cell as well, because colour alone is not readable for everyone.
 */
function cellTone(rank: number | null): string {
    if (rank === null) {
        return 'bg-muted text-muted-foreground';
    }

    if (rank <= 3) {
        return 'bg-emerald-600 text-white';
    }

    if (rank <= 10) {
        return 'bg-amber-500 text-white';
    }

    if (rank <= 20) {
        return 'bg-orange-500 text-white';
    }

    return 'bg-rose-600 text-white';
}

export function HeatmapGrid({ grid }: { grid: (number | null)[][] }) {
    const dimension = grid.length;

    if (dimension === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3">
            <div
                className="grid gap-1"
                style={{ gridTemplateColumns: `repeat(${dimension}, minmax(0, 1fr))` }}
                role="table"
                aria-label={`${dimension}×${dimension} の順位グリッド`}
            >
                {grid.map((row, rowIndex) =>
                    row.map((rank, colIndex) => (
                        <div
                            key={`${rowIndex}-${colIndex}`}
                            role="cell"
                            aria-label={`${rowIndex + 1}行${colIndex + 1}列: ${rank === null ? '圏外' : `${rank}位`}`}
                            className={cn(
                                'flex aspect-square items-center justify-center rounded-md text-sm font-semibold tabular-nums',
                                cellTone(rank),
                            )}
                        >
                            {rank ?? '—'}
                        </div>
                    )),
                )}
            </div>

            <HeatmapLegend />
        </div>
    );
}

export function HeatmapLegend() {
    const bands = [
        { label: '1〜3位', className: 'bg-emerald-600' },
        { label: '4〜10位', className: 'bg-amber-500' },
        { label: '11〜20位', className: 'bg-orange-500' },
        { label: '21位以下', className: 'bg-rose-600' },
        { label: '圏外', className: 'bg-muted border' },
    ];

    return (
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            {bands.map((band) => (
                <span key={band.label} className="flex items-center gap-1.5">
                    <span className={cn('size-3 rounded-sm', band.className)} aria-hidden="true" />
                    {band.label}
                </span>
            ))}
        </div>
    );
}
