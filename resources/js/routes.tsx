import { createBrowserRouter, Navigate } from 'react-router';

import { AppShell } from '@/components/app-shell';
import { RequireAuth, RequireGuest, RequireOrganization } from '@/components/route-guards';
import AcceptInvitation from '@/pages/accept-invitation';
import Dashboard from '@/pages/dashboard';
import GbpPosts from '@/pages/gbp-posts';
import Heatmaps from '@/pages/heatmaps';
import Login from '@/pages/login';
import NotFound from '@/pages/not-found';
import Proposals from '@/pages/proposals';
import Rankings from '@/pages/rankings';
import Register from '@/pages/register';
import Reviews from '@/pages/reviews';
import SelectOrganization from '@/pages/select-organization';
import Settings from '@/pages/settings';

export const router = createBrowserRouter([
    {
        element: <RequireGuest />,
        children: [
            { path: '/login', element: <Login /> },
            { path: '/register', element: <Register /> },
        ],
    },
    // Reachable either way: the invitee may or may not have an account yet.
    { path: '/invitations/:token', element: <AcceptInvitation /> },
    {
        element: <RequireAuth />,
        children: [
            { path: '/organizations', element: <SelectOrganization /> },
            {
                element: <RequireOrganization />,
                children: [
                    {
                        element: <AppShell />,
                        children: [
                            { path: '/', element: <Navigate to="/dashboard" replace /> },
                            { path: '/dashboard', element: <Dashboard /> },
                            // The screens read the store front from the
                            // switcher in the header rather than from the URL,
                            // so one path serves every store front and
                            // switching does not lose the reader's place.
                            { path: '/rankings', element: <Rankings /> },
                            { path: '/heatmaps', element: <Heatmaps /> },
                            { path: '/reviews', element: <Reviews /> },
                            { path: '/gbp-posts', element: <GbpPosts /> },
                            { path: '/proposals', element: <Proposals /> },
                            { path: '/settings', element: <Settings /> },
                            // The earlier paths, kept working.
                            { path: '/heatmap', element: <Navigate to="/heatmaps" replace /> },
                        ],
                    },
                ],
            },
        ],
    },
    { path: '*', element: <NotFound /> },
]);
