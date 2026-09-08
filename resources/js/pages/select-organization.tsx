import { Building2, ChevronRight } from 'lucide-react';
import { useNavigate } from 'react-router';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuthStore } from '@/stores/auth';
import { useOrganizationStore } from '@/stores/organization';

const roleLabels: Record<string, string> = {
    owner: 'オーナー',
    admin: '管理者',
    editor: '編集者',
    viewer: '閲覧者',
};

export default function SelectOrganization() {
    const organizations = useAuthStore((state) => state.organizations);
    const setCurrent = useOrganizationStore((state) => state.setCurrent);
    const navigate = useNavigate();

    function choose(organizationId: number) {
        setCurrent(organizationId);
        navigate('/dashboard', { replace: true });
    }

    return (
        <main className="flex min-h-screen items-center justify-center bg-muted/30 p-4">
            <Card className="w-full max-w-lg">
                <CardHeader>
                    <CardTitle>組織を選択</CardTitle>
                    <CardDescription>操作する組織を選んでください。あとからヘッダーで切り替えられます。</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                    {organizations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            所属している組織がありません。管理者に招待を依頼してください。
                        </p>
                    ) : (
                        organizations.map((organization) => (
                            <Button
                                key={organization.id}
                                variant="outline"
                                className="h-auto justify-between px-4 py-3"
                                onClick={() => choose(organization.id)}
                            >
                                <span className="flex min-w-0 items-center gap-3">
                                    <Building2 className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    <span className="flex min-w-0 flex-col items-start">
                                        <span className="truncate font-medium">{organization.name}</span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {roleLabels[organization.role] ?? organization.role}
                                            {organization.plan ? ` ・ ${organization.plan.name}` : ''}
                                        </span>
                                    </span>
                                </span>
                                <ChevronRight className="size-4 shrink-0 opacity-60" aria-hidden="true" />
                            </Button>
                        ))
                    )}
                </CardContent>
            </Card>
        </main>
    );
}
