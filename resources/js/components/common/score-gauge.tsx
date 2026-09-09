import { cn } from '@/lib/utils';

/**
 * The MEO score as a dial.
 *
 * Drawn as an SVG arc rather than a chart: it is one number, and a chart
 * library would bring a legend, a tooltip and a responsive container to say
 * what a circle says on its own. The colour bands are the same ones the score
 * cards use, so a reader learns them once.
 */
export function scoreTone(score: number | null): 'good' | 'fair' | 'poor' | 'unknown' {
    if (score === null) {
        return 'unknown';
    }

    if (score >= 70) {
        return 'good';
    }

    return score >= 40 ? 'fair' : 'poor';
}

const strokes: Record<ReturnType<typeof scoreTone>, string> = {
    good: 'stroke-emerald-500',
    fair: 'stroke-amber-500',
    poor: 'stroke-rose-500',
    unknown: 'stroke-muted-foreground/40',
};

const texts: Record<ReturnType<typeof scoreTone>, string> = {
    good: 'text-emerald-600',
    fair: 'text-amber-600',
    poor: 'text-rose-600',
    unknown: 'text-muted-foreground',
};

export function ScoreGauge({ score, size = 160 }: { score: number | null; size?: number }) {
    const tone = scoreTone(score);
    const radius = 54;
    const circumference = Math.PI * radius; // a half circle
    const filled = ((score ?? 0) / 100) * circumference;

    return (
        <div className="flex flex-col items-center" role="img" aria-label={`MEOスコア ${score ?? '未計測'}`}>
            <svg width={size} height={size * 0.6} viewBox="0 0 140 80" aria-hidden="true">
                <path
                    d="M 16 70 A 54 54 0 0 1 124 70"
                    fill="none"
                    strokeWidth="12"
                    strokeLinecap="round"
                    className="stroke-muted"
                />
                <path
                    d="M 16 70 A 54 54 0 0 1 124 70"
                    fill="none"
                    strokeWidth="12"
                    strokeLinecap="round"
                    strokeDasharray={`${filled} ${circumference}`}
                    className={cn('transition-[stroke-dasharray] duration-700', strokes[tone])}
                />
            </svg>
            <div className="-mt-6 text-center">
                <p className={cn('text-4xl font-semibold tabular-nums', texts[tone])}>
                    {score === null ? '—' : score.toFixed(1)}
                </p>
                <p className="text-xs text-muted-foreground">100点満点</p>
            </div>
        </div>
    );
}
