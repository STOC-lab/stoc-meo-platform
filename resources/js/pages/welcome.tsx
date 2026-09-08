import { Rocket } from 'lucide-react';

import { Button } from '@/components/ui/button';

export default function Welcome() {
    return (
        <main className="flex min-h-screen flex-col items-center justify-center gap-6 p-8">
            <div className="flex items-center gap-3">
                <Rocket className="size-8 text-primary" aria-hidden="true" />
                <h1 className="text-3xl font-semibold tracking-tight">STOC MEO Platform</h1>
            </div>
            <p className="max-w-prose text-center text-muted-foreground">
                React 19 + Vite + Tailwind CSS + shadcn/ui の土台が動作しています。
            </p>
            <Button onClick={() => console.log('shadcn/ui button OK')}>Phase 1 準備完了</Button>
        </main>
    );
}
