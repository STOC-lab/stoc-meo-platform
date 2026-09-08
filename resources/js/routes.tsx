import { createBrowserRouter, Navigate } from 'react-router';

import { AppShell } from '@/components/app-shell';
import { RequireAuth, RequireGuest, RequireOrganization } from '@/components/route-guards';
import Dashboard from '@/pages/dashboard';
import Login from '@/pages/login';
import NotFound from '@/pages/not-found';
import { Heatmap, Rankings, Reviews, Settings } from '@/pages/placeholder';
import SelectOrganization from '@/pages/select-organization';

export const router = createBrowserRouter([
    {
        element: <RequireGuest />,
        children: [{ path: '/login', element: <Login /> }],
    },
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
                            { path: '/rankings', element: <Rankings /> },
                            { path: '/heatmap', element: <Heatmap /> },
                            { path: '/reviews', element: <Reviews /> },
                            { path: '/settings', element: <Settings /> },
                        ],
                    },
                ],
            },
        ],
    },
    { path: '*', element: <NotFound /> },
]);
