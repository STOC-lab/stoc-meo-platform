import { useState } from 'react';
import { CreditCard, ExternalLink, Link2, Plus, Store, Users } from 'lucide-react';

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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurrentLocation, useLocations } from '@/hooks/use-locations';
import {
    useBrands,
    useConnectGoogle,
    useCreateLocation,
    useGbpConnection,
    useMembers,
    useReports,
} from '@/hooks/use-settings';
import { api, errorMessage } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useCurrentOrganization } from '@/stores/auth';

export default function Settings() {
    const organization = useCurrentOrganization();

    return (
        <div className="flex flex-col gap-6">
            <PageHeader title="設定" description={organization?.name} />

            <Tabs defaultValue="locations">
                <TabsList>
                    <TabsTrigger value="locations">店舗・ブランド</TabsTrigger>
                    <TabsTrigger value="members">メンバー</TabsTrigger>
                    <TabsTrigger value="connections">外部連携</TabsTrigger>
                    <TabsTrigger value="billing">プラン・請求</TabsTrigger>
                </TabsList>

                <TabsContent value="locations">
                    <LocationsPanel />
                </TabsContent>
                <TabsContent value="members">
                    <MembersPanel />
                </TabsContent>
                <TabsContent value="connections">
                    <ConnectionsPanel />
                </TabsContent>
                <TabsContent value="billing">
                    <BillingPanel />
                </TabsContent>
            </Tabs>
        </div>
    );
}

function LocationsPanel() {
    const locations = useLocations();
    const brands = useBrands();
    const createLocation = useCreateLocation();

    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({ name: '', address: '', phone: '', latitude: '', longitude: '' });

    async function submit(event: React.FormEvent) {
        event.preventDefault();

        try {
            await createLocation.mutateAsync({
                name: form.name.trim(),
                address: form.address.trim() === '' ? null : form.address.trim(),
                phone: form.phone.trim() === '' ? null : form.phone.trim(),
                latitude: form.latitude.trim() === '' ? null : Number(form.latitude),
                longitude: form.longitude.trim() === '' ? null : Number(form.longitude),
            });
            setForm({ name: '', address: '', phone: '', latitude: '', longitude: '' });
            setOpen(false);
        } catch {
            // The dialog stays open and shows the reason below.
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Store className="size-4 text-muted-foreground" aria-hidden="true" />
                            <CardTitle>店舗</CardTitle>
                        </div>
                        <Button size="sm" onClick={() => setOpen(true)}>
                            <Plus aria-hidden="true" />
                            店舗を追加
                        </Button>
                    </div>
                    <CardDescription>ヒートマップには緯度・経度の登録が必要です</CardDescription>
                </CardHeader>
                <CardContent>
                    {locations.isPending ? (
                        <LoadingState />
                    ) : locations.isError ? (
                        <ErrorState error={locations.error} />
                    ) : (locations.data?.length ?? 0) === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">まだ店舗がありません。</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>店舗名</TableHead>
                                    <TableHead>住所</TableHead>
                                    <TableHead className="w-28">GBP連携</TableHead>
                                    <TableHead className="w-28">座標</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {locations.data!.map((location) => (
                                    <TableRow key={location.id}>
                                        <TableCell className="font-medium">{location.name}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {location.address ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {location.linked_to_gbp ? (
                                                <Badge variant="success">連携済み</Badge>
                                            ) : (
                                                <Badge variant="secondary">未連携</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {location.has_coordinates ? (
                                                <Badge variant="success">登録済み</Badge>
                                            ) : (
                                                <Badge variant="warning">未登録</Badge>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>ブランド</CardTitle>
                    <CardDescription>複数店舗を束ねる単位</CardDescription>
                </CardHeader>
                <CardContent>
                    {brands.isPending ? (
                        <LoadingState />
                    ) : brands.isError ? (
                        <ErrorState error={brands.error} />
                    ) : (brands.data?.length ?? 0) === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            ブランドは登録されていません。
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {brands.data!.map((brand) => (
                                <li key={brand.id} className="rounded-md border px-3 py-2 text-sm">
                                    {brand.name}
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>店舗を追加</DialogTitle>
                            <DialogDescription>
                                緯度・経度はヒートマップのグリッド中心として使われます。
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="location-name">店舗名</Label>
                            <Input
                                id="location-name"
                                value={form.name}
                                onChange={(event) => setForm({ ...form, name: event.target.value })}
                                required
                                autoFocus
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="location-address">住所</Label>
                            <Input
                                id="location-address"
                                value={form.address}
                                onChange={(event) => setForm({ ...form, address: event.target.value })}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="location-lat">緯度</Label>
                                <Input
                                    id="location-lat"
                                    inputMode="decimal"
                                    value={form.latitude}
                                    onChange={(event) => setForm({ ...form, latitude: event.target.value })}
                                    placeholder="35.6580"
                                />
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="location-lng">経度</Label>
                                <Input
                                    id="location-lng"
                                    inputMode="decimal"
                                    value={form.longitude}
                                    onChange={(event) => setForm({ ...form, longitude: event.target.value })}
                                    placeholder="139.7016"
                                />
                            </div>
                        </div>

                        {createLocation.isError ? (
                            <p className="text-sm text-destructive">{errorMessage(createLocation.error)}</p>
                        ) : null}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                キャンセル
                            </Button>
                            <Button type="submit" disabled={createLocation.isPending}>
                                追加
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function MembersPanel() {
    const members = useMembers();

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-2">
                    <Users className="size-4 text-muted-foreground" aria-hidden="true" />
                    <CardTitle>メンバー</CardTitle>
                </div>
                <CardDescription>組織管理者のみ閲覧できます</CardDescription>
            </CardHeader>
            <CardContent>
                {members.isPending ? (
                    <LoadingState />
                ) : members.isError ? (
                    <ErrorState error={members.error} />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>名前</TableHead>
                                <TableHead>メール</TableHead>
                                <TableHead className="w-32">権限</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {(members.data ?? []).map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell className="font-medium">{member.name}</TableCell>
                                    <TableCell className="text-muted-foreground">{member.email}</TableCell>
                                    <TableCell>
                                        <Badge variant="outline">{member.role_label ?? member.role}</Badge>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function ConnectionsPanel() {
    const { location } = useCurrentLocation();
    const connection = useGbpConnection(location?.id ?? null);
    const connect = useConnectGoogle();

    if (location === null) {
        return <EmptyState title="店舗が登録されていません" description="先に店舗を追加してください。" />;
    }

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <Link2 className="size-4 text-muted-foreground" aria-hidden="true" />
                        <CardTitle>Googleビジネスプロフィール</CardTitle>
                    </div>
                    <CardDescription>{location.name}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    {connection.isPending ? (
                        <LoadingState />
                    ) : connection.isError ? (
                        <ErrorState error={connection.error} />
                    ) : connection.data === null ? (
                        <p className="text-sm text-muted-foreground">まだ連携されていません。</p>
                    ) : (
                        <div className="flex flex-col gap-2 text-sm">
                            <div className="flex items-center gap-2">
                                <Badge variant={connection.data.needs_reconnection ? 'destructive' : 'success'}>
                                    {connection.data.token_status_label}
                                </Badge>
                                <span className="text-muted-foreground">{connection.data.google_email}</span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                最終同期: {formatDateTime(connection.data.last_synced_at)}
                            </p>
                        </div>
                    )}

                    <Button
                        className="w-fit"
                        variant={connection.data ? 'outline' : 'default'}
                        disabled={connect.isPending}
                        onClick={() => connect.mutate(location.id)}
                    >
                        <ExternalLink aria-hidden="true" />
                        {connection.data ? '再接続する' : 'Googleと連携する'}
                    </Button>

                    {connect.isError ? <ErrorState error={connect.error} /> : null}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Instagram</CardTitle>
                    <CardDescription>{location.name}</CardDescription>
                </CardHeader>
                <CardContent>
                    <p className="text-sm text-muted-foreground">
                        Instagramの接続画面は未実装です。現在はアクセストークンを直接登録する運用となります。
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}

function BillingPanel() {
    const organization = useCurrentOrganization();
    const { location } = useCurrentLocation();
    const reports = useReports(location?.id ?? null);
    const [error, setError] = useState<unknown>(null);
    const [busy, setBusy] = useState(false);

    async function openPortal() {
        setBusy(true);
        setError(null);

        try {
            const { data } = await api.get<{ url: string }>('/billing/portal');
            window.location.href = data.url;
        } catch (portalError) {
            setError(portalError);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <CreditCard className="size-4 text-muted-foreground" aria-hidden="true" />
                        <CardTitle>プラン</CardTitle>
                    </div>
                    <CardDescription>{organization?.plan?.name ?? 'プラン未設定'}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="text-muted-foreground">契約状態</span>
                        <Badge variant={organization?.status === 'active' ? 'success' : 'warning'}>
                            {organization?.status ?? '—'}
                        </Badge>
                    </div>

                    <Button className="w-fit" variant="outline" disabled={busy} onClick={openPortal}>
                        <ExternalLink aria-hidden="true" />
                        請求ポータルを開く
                    </Button>

                    {error ? <ErrorState error={error} /> : null}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>月次レポート</CardTitle>
                    <CardDescription>毎月1日に自動作成されます</CardDescription>
                </CardHeader>
                <CardContent>
                    {reports.isPending ? (
                        <LoadingState />
                    ) : reports.isError ? (
                        <p className="text-sm text-muted-foreground">
                            ご利用中のプランではレポートをご利用いただけません。
                        </p>
                    ) : (reports.data?.length ?? 0) === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            まだレポートがありません。
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {reports.data!.map((report) => (
                                <li
                                    key={report.id}
                                    className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm"
                                >
                                    <span>{report.period_label}</span>
                                    <span className="flex items-center gap-2">
                                        <Badge variant={report.downloadable ? 'success' : 'secondary'}>
                                            {report.status_label}
                                        </Badge>
                                        {report.downloadable && location ? (
                                            <Button asChild size="sm" variant="outline">
                                                <a href={`/api/v1/locations/${location.id}/reports/${report.id}`}>
                                                    ダウンロード
                                                </a>
                                            </Button>
                                        ) : null}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
