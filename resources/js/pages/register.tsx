import { useState, type FormEvent } from 'react';
import { AlertCircle, Loader2, Rocket } from 'lucide-react';
import { Link, useNavigate } from 'react-router';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { errorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';

export default function Register() {
    const register = useAuthStore((state) => state.register);
    const navigate = useNavigate();

    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [organizationName, setOrganizationName] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            await register({
                name,
                email,
                organization_name: organizationName,
                password,
                password_confirmation: passwordConfirmation,
            });

            navigate('/dashboard', { replace: true });
        } catch (exception) {
            setError(errorMessage(exception, '登録に失敗しました。'));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <main className="flex min-h-screen items-center justify-center bg-muted/30 p-4">
            <Card className="w-full max-w-md">
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <Rocket className="size-5 text-primary" aria-hidden="true" />
                        <CardTitle>アカウントを作成</CardTitle>
                    </div>
                    <CardDescription>組織を作成し、オーナーとして利用を開始します。</CardDescription>
                </CardHeader>
                <CardContent>
                    <form className="flex flex-col gap-4" onSubmit={handleSubmit} noValidate>
                        {error !== null ? (
                            <Alert variant="destructive">
                                <AlertCircle aria-hidden="true" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        ) : null}

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="organization_name">組織名</Label>
                            <Input
                                id="organization_name"
                                name="organization_name"
                                required
                                autoComplete="organization"
                                placeholder="株式会社ストック"
                                value={organizationName}
                                onChange={(event) => setOrganizationName(event.target.value)}
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="name">お名前</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoComplete="name"
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="email">メールアドレス</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                required
                                autoComplete="email"
                                value={email}
                                onChange={(event) => setEmail(event.target.value)}
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password">パスワード</Label>
                            <Input
                                id="password"
                                name="password"
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
                                name="password_confirmation"
                                type="password"
                                required
                                autoComplete="new-password"
                                value={passwordConfirmation}
                                onChange={(event) => setPasswordConfirmation(event.target.value)}
                            />
                        </div>

                        <Button type="submit" disabled={submitting}>
                            {submitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
                            登録する
                        </Button>

                        <p className="text-center text-sm text-muted-foreground">
                            すでにアカウントをお持ちですか？{' '}
                            <Link to="/login" className="text-foreground underline underline-offset-4">
                                ログイン
                            </Link>
                        </p>
                    </form>
                </CardContent>
            </Card>
        </main>
    );
}
