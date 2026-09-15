import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { AlertCircle, Loader2, MailCheck } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api, errorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { useOrganizationStore } from '@/stores/organization';
import type { Invitation } from '@/types/invitation';

type LoadState = 'loading' | 'ready' | 'missing';

export default function AcceptInvitation() {
    const { token = '' } = useParams();
    const navigate = useNavigate();

    const status = useAuthStore((state) => state.status);
    const currentUser = useAuthStore((state) => state.user);
    const loadProfile = useAuthStore((state) => state.loadProfile);
    const setCurrentOrganization = useOrganizationStore((state) => state.setCurrent);

    const [loadState, setLoadState] = useState<LoadState>('loading');
    const [invitation, setInvitation] = useState<Invitation | null>(null);
    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const needsRegistration = invitation?.requires_registration ?? false;
    const needsPassword = invitation?.requires_password ?? false;
    const signedIn = status === 'authenticated';

    // Whoever is signed in here need not be the invitee — the link may have
    // been opened in a browser someone else was using, or by the shop owner
    // checking it. Accepting switches the session over rather than refusing.
    const signedInAsSomeoneElse =
        signedIn && invitation !== null && currentUser !== null
            ? currentUser.email.toLowerCase() !== invitation.email.toLowerCase()
            : false;

    useEffect(() => {
        if (status === 'idle') {
            void loadProfile();
        }
    }, [status, loadProfile]);

    useEffect(() => {
        let cancelled = false;

        api.get<{ invitation: Invitation }>(`invitations/${token}`)
            .then(({ data }) => {
                if (!cancelled) {
                    setInvitation(data.invitation);
                    setLoadState('ready');
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLoadState('missing');
                }
            });

        return () => {
            cancelled = true;
        };
    }, [token]);

    async function handleAccept(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            await api.post(`invitations/${token}/accept`, needsRegistration
                ? { name, password, password_confirmation: passwordConfirmation }
                : needsPassword
                    ? { password }
                    : {});

            // The membership list has changed, so pull it again and land the
            // user inside the organization they just joined.
            await loadProfile();

            if (invitation !== null) {
                setCurrentOrganization(invitation.organization.id);
            }

            navigate('/dashboard', { replace: true });
        } catch (exception) {
            setError(errorMessage(exception, '招待を承諾できませんでした。'));
        } finally {
            setSubmitting(false);
        }
    }

    if (loadState === 'loading' || status === 'idle' || status === 'loading') {
        return (
            <main className="flex min-h-screen items-center justify-center">
                <Loader2 className="size-6 animate-spin text-muted-foreground" aria-label="読み込み中" />
            </main>
        );
    }

    if (loadState === 'missing' || invitation === null) {
        return (
            <Shell title="招待が見つかりません" description="リンクが正しいかご確認ください。">
                <p className="text-sm text-muted-foreground">
                    招待が取り消されたか、URL が間違っている可能性があります。招待した方にご確認ください。
                </p>
            </Shell>
        );
    }

    if (invitation.accepted || invitation.expired) {
        return (
            <Shell
                title={invitation.accepted ? 'この招待は使用済みです' : 'この招待は期限切れです'}
                description={`${invitation.organization.name} への招待`}
            >
                <p className="text-sm text-muted-foreground">
                    {invitation.accepted
                        ? 'すでに承諾されています。ログインしてご利用ください。'
                        : '有効期限が過ぎています。招待した方に再送を依頼してください。'}
                </p>
                <Button asChild className="mt-4">
                    <Link to="/login">ログインへ</Link>
                </Button>
            </Shell>
        );
    }

    return (
        <Shell
            title={`${invitation.organization.name} への招待`}
            description={
                invitation.invited_by
                    ? `${invitation.invited_by} さんから ${invitation.role_label} として招待されています。`
                    : `${invitation.role_label} として招待されています。`
            }
        >
            <form className="flex flex-col gap-4" onSubmit={handleAccept} noValidate>
                {error !== null ? (
                    <Alert variant="destructive">
                        <AlertCircle aria-hidden="true" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                ) : null}

                {signedInAsSomeoneElse ? (
                    <Alert>
                        <AlertCircle aria-hidden="true" />
                        <AlertDescription>
                            現在 {currentUser?.email} でログイン中です。承諾すると {invitation.email} に切り替わります。
                        </AlertDescription>
                    </Alert>
                ) : null}

                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">メールアドレス</Label>
                    <Input id="email" value={invitation.email} readOnly disabled />
                </div>

                {needsPassword ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="password">パスワード</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            autoComplete="current-password"
                            value={password}
                            onChange={(event) => setPassword(event.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            {invitation.email} のアカウントは既に登録されています。そのパスワードを入力してください。
                        </p>
                    </div>
                ) : null}

                {needsRegistration ? (
                    <>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="name">お名前</Label>
                            <Input
                                id="name"
                                required
                                autoComplete="name"
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                            />
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password">パスワード</Label>
                            <Input
                                id="password"
                                type="password"
                                required
                                autoComplete="new-password"
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                            />
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password_confirmation">パスワード（確認）</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                required
                                autoComplete="new-password"
                                value={passwordConfirmation}
                                onChange={(event) => setPasswordConfirmation(event.target.value)}
                            />
                        </div>
                    </>
                ) : null}

                <Button type="submit" disabled={submitting}>
                    {submitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
                    招待を承諾する
                </Button>
            </form>
        </Shell>
    );
}

function Shell({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-muted/30 p-4">
            <Card className="w-full max-w-md">
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <MailCheck className="size-5 text-primary" aria-hidden="true" />
                        <CardTitle>{title}</CardTitle>
                    </div>
                    <CardDescription>{description}</CardDescription>
                </CardHeader>
                <CardContent>{children}</CardContent>
            </Card>
        </main>
    );
}
