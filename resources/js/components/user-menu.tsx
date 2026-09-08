import { LogOut, User as UserIcon } from 'lucide-react';
import { useNavigate } from 'react-router';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAuthStore } from '@/stores/auth';

function initials(name: string): string {
    return name.trim().slice(0, 2).toUpperCase();
}

export function UserMenu() {
    const user = useAuthStore((state) => state.user);
    const logout = useAuthStore((state) => state.logout);
    const navigate = useNavigate();

    if (user === null) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="rounded-full" aria-label="ユーザーメニュー">
                    <Avatar>
                        <AvatarFallback>{initials(user.name)}</AvatarFallback>
                    </Avatar>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>
                    <span className="flex flex-col">
                        <span className="text-sm font-medium text-foreground">{user.name}</span>
                        <span className="truncate text-xs">{user.email}</span>
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => navigate('/settings')}>
                    <UserIcon aria-hidden="true" />
                    アカウント設定
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={async () => {
                        await logout();
                        navigate('/login', { replace: true });
                    }}
                >
                    <LogOut aria-hidden="true" />
                    ログアウト
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
