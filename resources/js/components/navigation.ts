import { LayoutDashboard, Map, MessageSquare, Settings, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export interface NavigationItem {
    to: string;
    label: string;
    icon: LucideIcon;
}

export const navigation: NavigationItem[] = [
    { to: '/dashboard', label: 'ダッシュボード', icon: LayoutDashboard },
    { to: '/rankings', label: '順位', icon: TrendingUp },
    { to: '/heatmap', label: 'ヒートマップ', icon: Map },
    { to: '/reviews', label: '口コミ', icon: MessageSquare },
    { to: '/settings', label: '設定', icon: Settings },
];
