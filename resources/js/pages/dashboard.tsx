import { BarChart3, MapPin, MessageSquare, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentOrganization } from '@/stores/auth';

interface Widget {
    key: string;
    title: string;
    description: string;
    icon: LucideIcon;
    span: string;
}

/**
 * Placeholders for the widgets Phase 1 will fill in; each one keeps the space
 * and the heading its data will land in.
 */
const widgets: Widget[] = [
    {
        key: 'ranking',
        title: '検索順位',
        description: '計測中キーワードの推移',
        icon: TrendingUp,
        span: 'lg:col-span-2',
    },
    { key: 'heatmap', title: 'ヒートマップ', description: 'エリア別の表示順位', icon: MapPin, span: '' },
    { key: 'reviews', title: '口コミ', description: '新着と未返信の件数', icon: MessageSquare, span: '' },
    {
        key: 'insights',
        title: 'インサイト',
        description: '表示回数・アクション数',
        icon: BarChart3,
        span: 'lg:col-span-2',
    },
];

export default function Dashboard() {
    const organization = useCurrentOrganization();

    return (
        <div className="flex flex-col gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">ダッシュボード</h1>
                <p className="text-sm text-muted-foreground">
                    {organization?.name ?? '組織未選択'}
                    {organization?.plan ? ` ・ ${organization.plan.name}` : ''}
                </p>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {widgets.map(({ key, title, description, icon: Icon, span }) => (
                    <Card key={key} className={span}>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Icon className="size-4 text-muted-foreground" aria-hidden="true" />
                                <CardTitle>{title}</CardTitle>
                            </div>
                            <CardDescription>{description}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col gap-3" aria-hidden="true">
                                <Skeleton className="h-24 w-full" />
                                <Skeleton className="h-4 w-2/3" />
                                <Skeleton className="h-4 w-1/3" />
                            </div>
                            <p className="mt-4 text-xs text-muted-foreground">データ連携は次のフェーズで実装します。</p>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
}
