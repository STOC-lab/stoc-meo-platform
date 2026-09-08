import { Construction } from 'lucide-react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * A named, routable stand-in for a screen that is not built yet.
 */
export function PlaceholderPage({ title, description }: { title: string; description: string }) {
    return (
        <div className="flex flex-col gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>

            <Card>
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <Construction className="size-4 text-muted-foreground" aria-hidden="true" />
                        <CardTitle>準備中</CardTitle>
                    </div>
                    <CardDescription>この画面は今後のフェーズで実装します。</CardDescription>
                </CardHeader>
                <CardContent />
            </Card>
        </div>
    );
}

export function Rankings() {
    return <PlaceholderPage title="順位" description="キーワードごとの検索順位の推移。" />;
}

export function Heatmap() {
    return <PlaceholderPage title="ヒートマップ" description="エリアごとの表示順位の分布。" />;
}

export function Reviews() {
    return <PlaceholderPage title="口コミ" description="口コミの一覧と AI 返信。" />;
}

export function Settings() {
    return <PlaceholderPage title="設定" description="店舗・メンバー・プランの管理。" />;
}
