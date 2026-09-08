import { useEffect } from 'react';
import { Loader2 } from 'lucide-react';
import { Navigate, Outlet, useLocation } from 'react-router';

import { useAuthStore, useCurrentOrganization } from '@/stores/auth';

function Splash() {
    return (
        <div className="flex min-h-screen items-center justify-center">
            <Loader2 className="size-6 animate-spin text-muted-foreground" aria-label="読み込み中" />
        </div>
    );
}

/**
 * Resolves the session once on load, so a reload keeps the user signed in.
 */
export function RequireAuth() {
    const status = useAuthStore((state) => state.status);
    const loadProfile = useAuthStore((state) => state.loadProfile);
    const location = useLocation();

    useEffect(() => {
        if (status === 'idle') {
            void loadProfile();
        }
    }, [status, loadProfile]);

    if (status === 'idle' || status === 'loading') {
        return <Splash />;
    }

    if (status === 'guest') {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    return <Outlet />;
}

/**
 * Tenant-scoped screens need an organization; without one, go and pick.
 */
export function RequireOrganization() {
    const organization = useCurrentOrganization();

    if (organization === null) {
        return <Navigate to="/organizations" replace />;
    }

    return <Outlet />;
}

/**
 * Keeps a signed-in user out of the login screen.
 */
export function RequireGuest() {
    const status = useAuthStore((state) => state.status);
    const loadProfile = useAuthStore((state) => state.loadProfile);

    useEffect(() => {
        if (status === 'idle') {
            void loadProfile();
        }
    }, [status, loadProfile]);

    if (status === 'idle' || status === 'loading') {
        return <Splash />;
    }

    if (status === 'authenticated') {
        return <Navigate to="/dashboard" replace />;
    }

    return <Outlet />;
}
