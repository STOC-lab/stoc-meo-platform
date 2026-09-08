import { useState } from 'react';
import { Menu, Rocket, X } from 'lucide-react';
import { Link, Outlet } from 'react-router';

import { OrganizationSwitcher } from '@/components/organization-switcher';
import { SidebarNav } from '@/components/sidebar-nav';
import { UserMenu } from '@/components/user-menu';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The signed-in layout: a fixed sidebar on desktop that becomes a drawer on
 * small screens, with the organization switcher and user menu in the header.
 */
export function AppShell() {
    const [drawerOpen, setDrawerOpen] = useState(false);

    return (
        <div className="min-h-screen bg-muted/30">
            {drawerOpen ? (
                <button
                    type="button"
                    className="fixed inset-0 z-30 bg-foreground/20 lg:hidden"
                    aria-label="メニューを閉じる"
                    onClick={() => setDrawerOpen(false)}
                />
            ) : null}

            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r bg-background transition-transform lg:translate-x-0',
                    drawerOpen ? 'translate-x-0' : '-translate-x-full',
                )}
            >
                <div className="flex h-14 items-center justify-between gap-2 border-b px-4">
                    <Link to="/dashboard" className="flex items-center gap-2 font-semibold">
                        <Rocket className="size-5 text-primary" aria-hidden="true" />
                        STOC MEO
                    </Link>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="lg:hidden"
                        aria-label="メニューを閉じる"
                        onClick={() => setDrawerOpen(false)}
                    >
                        <X aria-hidden="true" />
                    </Button>
                </div>
                <SidebarNav onNavigate={() => setDrawerOpen(false)} />
            </aside>

            <div className="lg:pl-64">
                <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="lg:hidden"
                        aria-label="メニューを開く"
                        onClick={() => setDrawerOpen(true)}
                    >
                        <Menu aria-hidden="true" />
                    </Button>
                    <OrganizationSwitcher />
                    <div className="ml-auto">
                        <UserMenu />
                    </div>
                </header>

                <main className="p-4 md:p-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
