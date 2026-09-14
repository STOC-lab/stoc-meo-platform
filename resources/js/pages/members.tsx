import { useState } from 'react';
import { Mail, Trash2, UserPlus } from 'lucide-react';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/common/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/components/ui/toast';
import {
    useInvitations,
    useInviteMember,
    useMembers,
    useRemoveMember,
    useRevokeInvitation,
    useUpdateMemberRole,
} from '@/hooks/use-settings';
import { errorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useAuthStore, useCurrentOrganization } from '@/stores/auth';
import type { Member, OrganizationInvitation, Role } from '@/types/api';

/**
 * The five roles, weakest first, as they read to a shop owner.
 *
 * This application does not have the two-role "admin / member" split: the
 * middleware, the policies and the invitations all speak these five, and
 * collapsing them in the interface would mean the screen showed a role the
 * rest of the product does not have.
 */
const roles: { value: Role; label: string; hint: string }[] = [
    { value: 'viewer', label: '閲覧者', hint: '数字を見るだけ' },
    { value: 'staff', label: 'スタッフ', hint: '口コミ返信や投稿ができる' },
    { value: 'location_admin', label: '店舗管理者', hint: '担当店舗の設定を変更できる' },
    { value: 'org_admin', label: '組織管理者', hint: 'メンバーと店舗を管理できる' },
    { value: 'owner', label: 'オーナー', hint: '請求を含むすべて' },
];

const roleVariants: Record<Role, 'secondary' | 'outline' | 'default' | 'success'> = {
    viewer: 'secondary',
    staff: 'outline',
    location_admin: 'outline',
    org_admin: 'default',
    owner: 'success',
};

function roleLabel(role: Role): string {
    return roles.find((entry) => entry.value === role)?.label ?? role;
}

export default function Members() {
    const organization = useCurrentOrganization();
    const currentUser = useAuthStore((state) => state.user);

    const members = useMembers();
    const invitations = useInvitations();

    const [inviting, setInviting] = useState(false);

    const owners = (members.data ?? []).filter((member) => member.role === 'owner');

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="メンバー"
                description={organization?.name}
                action={
                    <Button onClick={() => setInviting(true)}>
                        <UserPlus aria-hidden="true" />
                        メンバーを招待
                    </Button>
                }
            />

            <Card>
                <CardHeader>
                    <CardTitle>メンバー</CardTitle>
                    <CardDescription>
                        {members.data ? `${members.data.length} 人` : '組織に所属している人'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {members.isPending ? (
                        <LoadingState />
                    ) : members.isError ? (
                        <ErrorState error={members.error} />
                    ) : (members.data ?? []).length === 0 ? (
                        <EmptyState title="メンバーがいません" description="招待するとここに表示されます。" />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>名前</TableHead>
                                        <TableHead>メールアドレス</TableHead>
                                        <TableHead className="w-44">ロール</TableHead>
                                        <TableHead className="w-32">参加日</TableHead>
                                        <TableHead className="w-24 text-right">操作</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(members.data ?? []).map((member) => (
                                        <MemberRow
                                            key={member.id}
                                            member={member}
                                            isSelf={member.id === currentUser?.id}
                                            isLastOwner={member.role === 'owner' && owners.length === 1}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            <PendingInvitations
                invitations={invitations.data ?? []}
                isPending={invitations.isPending}
                error={invitations.isError ? invitations.error : null}
            />

            <InviteDialog open={inviting} onOpenChange={setInviting} />
        </div>
    );
}

/**
 * One member, with the two things that can be done to them.
 *
 * Both refusals the API makes are also made here, so the reader is not offered
 * an action that cannot succeed: you cannot remove yourself, and the last
 * owner can be neither demoted nor removed. The API enforces both regardless —
 * this is the courtesy, not the guard.
 */
function MemberRow({
    member,
    isSelf,
    isLastOwner,
}: {
    member: Member;
    isSelf: boolean;
    isLastOwner: boolean;
}) {
    const { toast } = useToast();
    const updateRole = useUpdateMemberRole();
    const removeMember = useRemoveMember();

    const [confirming, setConfirming] = useState(false);

    function changeRole(role: Role) {
        if (role === member.role) {
            return;
        }

        updateRole.mutate(
            { id: member.id, role },
            {
                onSuccess: () => toast(`${member.name} のロールを${roleLabel(role)}に変更しました。`),
                onError: (error) => toast(errorMessage(error), 'error'),
            },
        );
    }

    function remove() {
        removeMember.mutate(member.id, {
            onSuccess: () => {
                setConfirming(false);
                toast(`${member.name} を組織から削除しました。`);
            },
            onError: (error) => {
                setConfirming(false);
                toast(errorMessage(error), 'error');
            },
        });
    }

    const removalReason = isSelf
        ? '自分自身は削除できません'
        : isLastOwner
          ? '組織には少なくとも1人のオーナーが必要です'
          : null;

    return (
        <TableRow>
            <TableCell className="font-medium">
                {member.name}
                {isSelf ? <span className="ml-2 text-xs text-muted-foreground">(あなた)</span> : null}
            </TableCell>
            <TableCell className="text-muted-foreground">{member.email}</TableCell>
            <TableCell>
                {isLastOwner ? (
                    <span title="組織には少なくとも1人のオーナーが必要です">
                        <Badge variant={roleVariants[member.role]}>{roleLabel(member.role)}</Badge>
                    </span>
                ) : (
                    <Select
                        value={member.role}
                        onValueChange={(value) => changeRole(value as Role)}
                        disabled={updateRole.isPending}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((role) => (
                                <SelectItem key={role.value} value={role.value}>
                                    {role.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </TableCell>
            <TableCell className="text-muted-foreground tabular-nums">{formatDate(member.joined_at)}</TableCell>
            <TableCell className="text-right">
                <span title={removalReason ?? undefined}>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={removalReason !== null || removeMember.isPending}
                        onClick={() => setConfirming(true)}
                        aria-label={`${member.name} を削除`}
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                </span>

                <Dialog open={confirming} onOpenChange={setConfirming}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>メンバーを削除しますか？</DialogTitle>
                            <DialogDescription>
                                {member.name}（{member.email}）は組織のデータにアクセスできなくなります。
                                アカウント自体は削除されません。
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setConfirming(false)}>
                                キャンセル
                            </Button>
                            <Button variant="destructive" disabled={removeMember.isPending} onClick={remove}>
                                削除する
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </TableCell>
        </TableRow>
    );
}

function PendingInvitations({
    invitations,
    isPending,
    error,
}: {
    invitations: OrganizationInvitation[];
    isPending: boolean;
    error: unknown;
}) {
    const { toast } = useToast();
    const revoke = useRevokeInvitation();

    if (isPending) {
        return null;
    }

    if (error !== null) {
        return <ErrorState error={error} />;
    }

    if (invitations.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-2">
                    <Mail className="size-4 text-muted-foreground" aria-hidden="true" />
                    <CardTitle>招待中</CardTitle>
                </div>
                <CardDescription>
                    承諾されるまでの間もプランの人数に数えられます
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>メールアドレス</TableHead>
                                <TableHead className="w-36">ロール</TableHead>
                                <TableHead className="w-32">期限</TableHead>
                                <TableHead className="w-24 text-right">操作</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invitations.map((invitation) => (
                                <TableRow key={invitation.id}>
                                    <TableCell className="font-medium">{invitation.email}</TableCell>
                                    <TableCell>
                                        <Badge variant="outline">{roleLabel(invitation.role)}</Badge>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {invitation.expired ? (
                                            <span className="text-destructive">期限切れ</span>
                                        ) : (
                                            formatDate(invitation.expires_at)
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={revoke.isPending}
                                            onClick={() =>
                                                revoke.mutate(invitation.id, {
                                                    onSuccess: () => toast('招待を取り消しました。'),
                                                    onError: (error) => toast(errorMessage(error), 'error'),
                                                })
                                            }
                                        >
                                            取り消す
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}

function InviteDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { toast } = useToast();
    const invite = useInviteMember();

    const [email, setEmail] = useState('');
    const [role, setRole] = useState<Role>('staff');

    async function submit(event: React.FormEvent) {
        event.preventDefault();

        try {
            await invite.mutateAsync({ email: email.trim(), role });

            setEmail('');
            setRole('staff');
            onOpenChange(false);
            toast(`${email.trim()} に招待メールを送信しました。`);
        } catch {
            // The dialog stays open and shows the reason below the form.
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>メンバーを招待</DialogTitle>
                        <DialogDescription>
                            招待リンクをメールでお送りします。アカウントをお持ちでない方は、リンクから登録できます。
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="invite-email">メールアドレス</Label>
                        <Input
                            id="invite-email"
                            type="email"
                            value={email}
                            onChange={(event) => setEmail(event.target.value)}
                            placeholder="name@example.com"
                            required
                            autoFocus
                        />
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="invite-role">ロール</Label>
                        <Select value={role} onValueChange={(value) => setRole(value as Role)}>
                            <SelectTrigger id="invite-role">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((entry) => (
                                    <SelectItem key={entry.value} value={entry.value}>
                                        {entry.label} — {entry.hint}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {invite.isError ? (
                        <p className="text-sm text-destructive">{errorMessage(invite.error)}</p>
                    ) : null}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            キャンセル
                        </Button>
                        <Button type="submit" disabled={invite.isPending}>
                            <Mail aria-hidden="true" />
                            招待を送信
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
