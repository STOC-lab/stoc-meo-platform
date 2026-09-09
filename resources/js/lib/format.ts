/**
 * Formatting helpers shared by the screens, so a rank or a date reads the same
 * everywhere it appears.
 */

/** A position, or the dash that stands for "did not appear". */
export function formatRank(rank: number | null | undefined): string {
    return rank === null || rank === undefined ? '—' : `${rank}位`;
}

export function formatNumber(value: number | null | undefined, suffix = ''): string {
    return value === null || value === undefined ? '—' : `${value}${suffix}`;
}

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('ja-JP', { year: 'numeric', month: 'short', day: 'numeric' });
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('ja-JP', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * How a rank moved between two checks, from the reader's point of view: a
 * smaller number is an improvement, so the sign is flipped.
 */
export function rankDelta(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null) {
        return null;
    }

    return previous - current;
}
