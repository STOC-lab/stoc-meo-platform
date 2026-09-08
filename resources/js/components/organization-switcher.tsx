import { Building2, ChevronsUpDown } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuCheckedItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { useAuthStore, useCurrentOrganization } from '@/stores/auth';
import { useOrganizationStore } from '@/stores/organization';

const roleLabels: Record<string, string> = {
    owner: 'オーナー',
    admin: '管理者',
    editor: '編集者',
    viewer: '閲覧者',
};

export function OrganizationSwitcher() {
    const organizations = useAuthStore((state) => state.organizations);
    const setCurrent = useOrganizationStore((state) => state.setCurrent);
    const current = useCurrentOrganization();

    if (organizations.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" className="max-w-[16rem] justify-between gap-2">
                    <Building2 className="size-4 shrink-0" aria-hidden="true" />
                    <span className="truncate">{current?.name ?? '組織を選択'}</span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-60" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-64">
                <DropdownMenuLabel>組織を切り替え</DropdownMenuLabel>
                {organizations.map((organization) => (
                    <DropdownMenuCheckedItem
                        key={organization.id}
                        checked={organization.id === current?.id}
                        onSelect={() => setCurrent(organization.id)}
                    >
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate">{organization.name}</span>
                            <span className="truncate text-xs text-muted-foreground">
                                {roleLabels[organization.role] ?? organization.role}
                                {organization.plan ? ` ・ ${organization.plan.name}` : ''}
                            </span>
                        </span>
                    </DropdownMenuCheckedItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
