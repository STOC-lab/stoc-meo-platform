import {
    Image,
    LayoutDashboard,
    Lightbulb,
    Map,
    MessageSquare,
    Settings,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export interface NavigationItem {
    to: string;
    label: string;
    icon: LucideIcon;
}

export const navigation: NavigationItem[] = [
    { to: '/dashboard', label: 'ダッシュボード', icon: LayoutDashboard },
    { to: '/rankings', label: '順位', icon: TrendingUp },
    { to: '/heatmaps', label: 'ヒートマップ', icon: Map },
    { to: '/reviews', label: '口コミ', icon: MessageSquare },
    { to: '/gbp-posts', label: '投稿', icon: Image },
    { to: '/proposals', label: '改善提案', icon: Lightbulb },
    { to: '/settings', label: '設定', icon: Settings },
];
