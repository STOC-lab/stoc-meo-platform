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

api.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        // 419 is a stale session cookie, which the user recovers from the same
        // way as a plain 401: by signing in again.
        if (error.response?.status === 401 || error.response?.status === 419) {
            onUnauthenticated?.();
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
