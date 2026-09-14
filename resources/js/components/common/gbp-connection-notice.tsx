import { AlertCircle, Link2, RefreshCw } from 'lucide-react';
import { Link } from 'react-router';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useGbpConnection } from '@/hooks/use-settings';
import { formatDate } from '@/lib/format';

/**
 * Whether the Business Profile screens can expect anything from Google.
 *
 * The three unhappy states need different words from the shop owner's point of
 * view, and telling them apart matters: "connect your profile" said to someone
 * who connected it last week reads as the application being broken, and they
 * wait for content that is never coming.
 */
export type GbpReadiness = 'pending' | 'missing' | 'reconnect' | 'never-synced' | 'ready';

export function useGbpReadiness(locationId: number | null) {
    const connection = useGbpConnection(locationId);

    const state: GbpReadiness = connection.isPending
        ? 'pending'
        : connection.data == null
          ? 'missing'
          : connection.data.needs_reconnection
            ? 'reconnect'
            : connection.data.last_synced_at === null
              ? 'never-synced'
              : 'ready';

    return { state, connection: connection.data ?? null };
}

/**
 * Says why a Business Profile screen has nothing on it, when the reason is the
 * connection rather than the shop.
 *
 * Renders nothing while the connection is loading or once it is working, so a
 * screen can place this above its content unconditionally.
 */
export function GbpConnectionNotice({ locationId }: { locationId: number | null }) {
    const { state, connection } = useGbpReadiness(locationId);

    if (state === 'pending' || state === 'ready') {
        return null;
    }

    const settingsLink = (
        <Button asChild size="sm" variant="outline" className="mt-2">
            <Link to="/settings">設定を開く</Link>
        </Button>
    );

    if (state === 'missing') {
        return (
            <Alert>
                <Link2 aria-hidden="true" />
                <AlertTitle>Googleビジネスプロフィールが連携されていません</AlertTitle>
                <AlertDescription>
                    口コミの取り込みと投稿にはGoogleとの連携が必要です。
                    {settingsLink}
                </AlertDescription>
            </Alert>
        );
    }

    if (state === 'reconnect') {
        return (
            <Alert variant="destructive">
                <AlertCircle aria-hidden="true" />
                <AlertTitle>Google連携が切れています</AlertTitle>
                <AlertDescription>
                    {connection?.token_status_label ?? '再接続が必要です'}。
                    再接続するまで口コミの取り込みも投稿もできません。
                    {settingsLink}
                </AlertDescription>
            </Alert>
        );
    }

    // Connected, the token is good, and no sweep has ever finished. The cause
    // is at Google's end — most often an API that is not enabled on the
    // project — so this points at someone who can look rather than asking the
    // shop owner to reconnect something that is not broken.
    return (
        <Alert>
            <RefreshCw aria-hidden="true" />
            <AlertTitle>まだ一度も取り込めていません</AlertTitle>
            <AlertDescription>
                {connection?.google_email ? `${connection.google_email} と` : 'Googleと'}
                {connection?.connected_at ? `${formatDate(connection.connected_at)}に` : ''}
                連携済みで、接続は有効です。ただしGoogleからの取得がまだ一度も成功していません。
                しばらく経っても変わらない場合は、管理者にお問い合わせください。
            </AlertDescription>
        </Alert>
    );
}
