import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';

import '../css/app.css';
import { ToastProvider } from '@/components/ui/toast';
import { queryClient } from '@/lib/query';
import { router } from '@/routes';

const container = document.getElementById('app');

if (container) {
    createRoot(container).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <ToastProvider>
                    <RouterProvider router={router} />
                </ToastProvider>
            </QueryClientProvider>
        </StrictMode>,
    );
}
