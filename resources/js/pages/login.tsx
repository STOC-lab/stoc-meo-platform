import { useState, type FormEvent } from 'react';
import { AlertCircle, Loader2, Rocket } from 'lucide-react';
import { useNavigate } from 'react-router';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { errorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { useOrganizationStore } from '@/stores/organization';

export default function Login() {
    const login = useAuthStore((state) => state.login);
    const navigate = useNavigate();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            await login(email, password, remember);

            // A single membership is selected during login; anything else has
            // to be chosen first.
            navigate(useOrganizationStore.getState().currentId === null ? '/organizations' : '/dashboard', {
                replace: true,
            });
        } catch (exception) {
            setError(errorMessage(exception, 'ログインに失敗しました。'));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <main className="flex min-h-screen items-center justify-center bg-muted/30 p-4">
            <Card className="w-full max-w-sm">
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <Rocket className="size-5 text-primary" aria-hidden="true" />
                        <CardTitle>STOC MEO にログイン</CardTitle>
                    </div>
                    <CardDescription>登録済みのメールアドレスとパスワードを入力してください。</CardDescription>
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
                            <Label htmlFor="email">メールアドレス</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                required
                                value={email}
                                aria-invalid={error !== null}
                                onChange={(event) => setEmail(event.target.value)}
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password">パスワード</Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                autoComplete="current-password"
                                required
                                value={password}
                                aria-invalid={error !== null}
                                onChange={(event) => setPassword(event.target.value)}
                            />
                        </div>

                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                            <input
                                type="checkbox"
                                className="size-4 rounded border-input"
                                checked={remember}
                                onChange={(event) => setRemember(event.target.checked)}
                            />
                            ログイン状態を保持する
                        </label>

                        <Button type="submit" disabled={submitting}>
                            {submitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
                            ログイン
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </main>
    );
}
