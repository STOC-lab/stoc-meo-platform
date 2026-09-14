import axios, { type AxiosError } from 'axios';

/**
 * The API client. Sanctum authenticates the SPA with a session cookie, so
 * every request carries credentials and the XSRF header axios reads from the
 * cookie Laravel sets.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Laravel issues the XSRF cookie on this endpoint; it has to be fetched before
 * the first stateful write (i.e. before logging in).
 */
export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

/**
 * Every tenant-scoped endpoint reads the active organization from this header.
 */
export function setOrganizationHeader(organizationId: number | null): void {
    if (organizationId === null) {
        delete api.defaults.headers.common['X-Organization-Id'];

        return;
    }

    api.defaults.headers.common['X-Organization-Id'] = String(organizationId);
}

let onUnauthenticated: (() => void) | null = null;

/**
 * Registered by the auth store, which cannot be imported here without a cycle.
 */
export function handleUnauthenticated(handler: () => void): void {
    onUnauthenticated = handler;
}

/**
 * What the API says when a plan does not stretch to something.
 *
 * `feature` is always there; `limit` and `used` only when the allowance was
 * spent rather than absent, and `upgrade` is the call to action the API picks.
 */
export interface EntitlementRefusal {
    message: string;
    feature: string;
    limit?: number | null;
    used?: number;
    remaining?: number | null;
    upgrade?: {
        required: boolean;
        headline: string;
        cta_label: string;
        cta_url: string;
        current_plan: { code: string; name: string } | null;
        recommended_plan: { code: string; name: string } | null;
    } | null;
}

/**
 * A 403 carrying a `feature` is the plan talking, not the permissions: the
 * request was understood and refused because of what the organization pays
 * for. Those are worth saying out loud wherever they happen, since the caller
 * often has nowhere to put the message — a background refetch, a mutation
 * whose screen has already moved on.
 *
 * A 403 without a `feature` is an ordinary authorization refusal and is left
 * to the caller.
 */
export function entitlementRefusal(error: unknown): EntitlementRefusal | null {
    if (!axios.isAxiosError(error) || error.response?.status !== 403) {
        return null;
    }

    const data = error.response.data as Partial<EntitlementRefusal> | undefined;

    if (typeof data?.feature !== 'string' || typeof data.message !== 'string') {
        return null;
    }

    return data as EntitlementRefusal;
}

let onEntitlementRefused: ((refusal: EntitlementRefusal) => void) | null = null;

/**
 * Registered by the toast provider. Nothing is swallowed: the rejection still
 * reaches the caller, which decides what the screen does about it.
 */
export function handleEntitlementRefusal(handler: (refusal: EntitlementRefusal) => void): void {
    onEntitlementRefused = handler;
}

api.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        // 419 is a stale session cookie, which the user recovers from the same
        // way as a plain 401: by signing in again.
        if (error.response?.status === 401 || error.response?.status === 419) {
            onUnauthenticated?.();
        }

        const refusal = entitlementRefusal(error);

        if (refusal !== null) {
            onEntitlementRefused?.(refusal);
        }

        return Promise.reject(error);
    },
);

/**
 * Pulls a human-readable message out of a Laravel error response.
 */
export function errorMessage(error: unknown, fallback = '予期しないエラーが発生しました。'): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const firstValidationError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

    return firstValidationError ?? data?.message ?? fallback;
}
