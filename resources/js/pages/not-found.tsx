import { Link } from 'react-router';

import { Button } from '@/components/ui/button';

export default function NotFound() {
    return (
        <main className="flex min-h-screen flex-col items-center justify-center gap-4 p-8">
            <p className="text-sm font-medium text-muted-foreground">404</p>
            <h1 className="text-2xl font-semibold tracking-tight">ページが見つかりません</h1>
            <Button asChild>
                <Link to="/dashboard">ダッシュボードへ戻る</Link>
            </Button>
        </main>
    );
}
