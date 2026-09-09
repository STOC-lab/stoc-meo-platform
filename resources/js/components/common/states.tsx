import { AlertCircle, Inbox, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import { errorMessage } from '@/lib/api';
import { cn } from '@/lib/utils';

/**
 * The three things every data screen has to say when it has no content to
 * show, kept in one place so they read identically across the app.
 */

export function LoadingState({ label = '読み込み中…', className }: { label?: string; className?: string }) {
    return (
        <div className={cn('flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground', className)}>
            <Loader2 className="size-4 animate-spin" aria-hidden="true" />
            <span>{label}</span>
        </div>
    );
}

export function ErrorState({ error, className }: { error: unknown; className?: string }) {
    return (
        <div
            role="alert"
            className={cn(
                'flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive',
                className,
            )}
        >
            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{errorMessage(error)}</span>
        </div>
    );
}

export function EmptyState({
    title,
    description,
    action,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <Card className={className}>
            <CardContent className="flex flex-col items-center gap-3 py-10 text-center">
                <Inbox className="size-8 text-muted-foreground" aria-hidden="true" />
                <div>
                    <p className="text-sm font-medium">{title}</p>
                    {description ? <p className="mt-1 text-sm text-muted-foreground">{description}</p> : null}
                </div>
                {action}
            </CardContent>
        </Card>
    );
}

/**
 * The heading every screen opens with.
 */
export function PageHeader({
    title,
    description,
    action,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
            </div>
            {action}
        </div>
    );
}
