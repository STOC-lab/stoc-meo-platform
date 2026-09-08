import { NavLink } from 'react-router';

import { navigation } from '@/components/navigation';
import { cn } from '@/lib/utils';

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
    return (
        <nav className="flex flex-col gap-1 p-3" aria-label="メインナビゲーション">
            {navigation.map(({ to, label, icon: Icon }) => (
                <NavLink
                    key={to}
                    to={to}
                    onClick={onNavigate}
                    className={({ isActive }) =>
                        cn(
                            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            isActive
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-accent/60 hover:text-accent-foreground',
                        )
                    }
                >
                    <Icon className="size-4 shrink-0" aria-hidden="true" />
                    {label}
                </NavLink>
            ))}
        </nav>
    );
}
