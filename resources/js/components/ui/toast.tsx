import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { AlertCircle, CheckCircle2, X } from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * The short confirmations and refusals that belong beside an action rather
 * than in the page.
 *
 * Deliberately small and dependency-free: the application ships one bundle
 * already larger than it wants to be, and this needs to do four things —
 * appear, say one sentence, be dismissable, and go away.
 *
 * An error toast stays until it is dismissed. A success one does not: it is
 * confirming something the reader just did and already expects.
 */
export interface Toast {
    id: number;
    message: string;
    tone: 'success' | 'error';
}

interface ToastContextValue {
    toast: (message: string, tone?: Toast['tone']) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

const SUCCESS_MS = 4000;

export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<Toast[]>([]);

    const dismiss = useCallback((id: number) => {
        setToasts((current) => current.filter((entry) => entry.id !== id));
    }, []);

    const toast = useCallback((message: string, tone: Toast['tone'] = 'success') => {
        setToasts((current) => [...current, { id: Date.now() + Math.random(), message, tone }]);
    }, []);

    const value = useMemo(() => ({ toast }), [toast]);

    return (
        <ToastContext.Provider value={value}>
            {children}
            <div
                aria-live="polite"
                className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end"
            >
                {toasts.map((entry) => (
                    <ToastRow key={entry.id} toast={entry} onDismiss={dismiss} />
                ))}
            </div>
        </ToastContext.Provider>
    );
}

function ToastRow({ toast, onDismiss }: { toast: Toast; onDismiss: (id: number) => void }) {
    useEffect(() => {
        if (toast.tone === 'error') {
            return;
        }

        const timer = window.setTimeout(() => onDismiss(toast.id), SUCCESS_MS);

        return () => window.clearTimeout(timer);
    }, [toast, onDismiss]);

    const Icon = toast.tone === 'error' ? AlertCircle : CheckCircle2;

    return (
        <div
            role={toast.tone === 'error' ? 'alert' : 'status'}
            className={cn(
                'pointer-events-auto flex w-full max-w-sm items-start gap-2 rounded-lg border p-3 text-sm shadow-lg',
                toast.tone === 'error'
                    ? 'border-destructive/40 bg-card text-destructive'
                    : 'border-border bg-card text-card-foreground',
            )}
        >
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span className="flex-1">{toast.message}</span>
            <button
                type="button"
                onClick={() => onDismiss(toast.id)}
                className="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="閉じる"
            >
                <X className="size-4" aria-hidden="true" />
            </button>
        </div>
    );
}

/**
 * Outside a provider this is a no-op rather than a crash: a toast that does
 * not appear is a smaller failure than a screen that will not render.
 */
export function useToast(): ToastContextValue {
    return useContext(ToastContext) ?? { toast: () => {} };
}
