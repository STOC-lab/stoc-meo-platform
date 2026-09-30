import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router';
import { ArrowUpCircle, CreditCard, ExternalLink, Link2, Plus, Store, Users } from 'lucide-react';

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
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/components/ui/toast';
import { useCurrentLocation, useLocations } from '@/hooks/use-locations';
import {
    useBillingPlans,
    useBrands,
    useConnectGoogle,
    useConnectInstagram,
    useCreateLocation,
    useGbpAccounts,
    useGbpConnection,
    useGbpLocations,
    useInstagramConnection,
    useMembers,
    useReports,
    useSelectGbpLocation,
    useStartCheckout,
    useToggleGbpProtection,
} from '@/hooks/use-settings';
import { api, errorMessage } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { useCurrentOrganization } from '@/stores/auth';
import type { BillingPlan, GbpConnection, Location } from '@/types/api';

const TABS = ['locations', 'members', 'connections', 'billing'] as const;

type TabValue = (typeof TABS)[number];

export default function Settings() {
    const organization = useCurrentOrganization();
    const [params, setParams] = useSearchParams();
    const { toast } = useToast();

    const requested = params.get('tab');
    const tab: TabValue = TABS.includes(requested as TabValue) ? (requested as TabValue) : 'locations';

    // Stripe Checkout returns the customer to /settings?tab=billing with how
    // it went. Say so once, then drop the parameter so a refresh or a shared
    // link does not repeat it.
    const checkout = params.get('checkout');

    useEffect(() => {
        if (checkout === null) {
            return;
        }

        if (checkout === 'success') {
            toast('ご契約ありがとうございます。プランの反映まで数秒かかることがあります。');
        }

        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                next.delete('checkout');

                return next;
            },
            { replace: true },
        );
    }, [checkout, setParams, toast]);

    // The Google consent screen returns here through the API's callback, with
    // how it went. Same treatment as Checkout: say it once, then drop it.
    const google = params.get('google');
    const callbackMessage = params.get('message');

    useEffect(() => {
        if (google === null) {
            return;
        }

        if (google === 'success') {
            toast('Googleと連携しました');
        } else {
            toast(callbackMessage ?? 'Googleとの連携に失敗しました。', 'error');
        }

        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                next.delete('google');
                next.delete('message');

                return next;
            },
            { replace: true },
        );
    }, [google, callbackMessage, setParams, toast]);

    // Instagram's consent screen comes back the same way.
    const instagram = params.get('instagram');

    useEffect(() => {
        if (instagram === null) {
            return;
        }

        if (instagram === 'success') {
            toast('Instagramと連携しました');
        } else {
            toast(callbackMessage ?? 'Instagramとの連携に失敗しました。', 'error');
        }

        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                next.delete('instagram');
                next.delete('message');

                return next;
            },
            { replace: true },
        );
    }, [instagram, callbackMessage, setParams, toast]);

    function selectTab(value: string) {
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                next.set('tab', value);

                return next;
            },
            { replace: true },
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <PageHeader title="設定" description={organization?.name} />

            <Tabs value={tab} onValueChange={selectTab}>
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
    const toggleProtection = useToggleGbpProtection();
    const { toast } = useToast();

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

    async function setProtection(location: Location, enabled: boolean) {
        try {
            await toggleProtection.mutateAsync({ id: location.id, enabled });
            toast(
                enabled
                    ? 'GBP情報の保護をオンにしました。現在のGoogle上の内容を正として毎晩確認します。'
                    : 'GBP情報の保護をオフにしました。',
            );
        } catch (error) {
            toast(errorMessage(error), 'error');
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
                                    <TableHead className="w-40">GBP情報を保護する</TableHead>
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
                                            <div className="flex flex-col gap-1">
                                                <Switch
                                                    checked={location.gbp_protected}
                                                    disabled={!location.linked_to_gbp || toggleProtection.isPending}
                                                    onCheckedChange={(enabled) => setProtection(location, enabled)}
                                                    aria-label={`${location.name}のGBP情報を保護する`}
                                                    title={
                                                        location.linked_to_gbp
                                                            ? '店舗名・電話番号・ウェブサイト・営業時間・説明文がGoogle上で変更されたら元に戻します'
                                                            : 'GBP連携後に設定できます'
                                                    }
                                                />
                                                {location.gbp_protected && location.gbp_last_verified_at !== null && (
                                                    <span className="text-xs text-muted-foreground">
                                                        確認 {formatDateTime(location.gbp_last_verified_at)}
                                                    </span>
                                                )}
                                            </div>
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

                    {connection.data && !connection.data.needs_reconnection ? (
                        <GbpProfilePicker location={location} connection={connection.data} />
                    ) : null}
                </CardContent>
            </Card>

            <InstagramConnectionCard location={location} />
        </div>
    );
}

function InstagramConnectionCard({ location }: { location: Location }) {
    const connection = useInstagramConnection(location.id);
    const connect = useConnectInstagram();

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-2">
                    <Link2 className="size-4 text-muted-foreground" aria-hidden="true" />
                    <CardTitle>Instagram</CardTitle>
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
                            <span className="text-muted-foreground">
                                {connection.data.username ? `@${connection.data.username}` : connection.data.ig_user_id}
                            </span>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            接続日: {formatDate(connection.data.connected_at)}
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
                    {connection.data ? '再接続する' : 'Instagramと連携する'}
                </Button>

                {connect.isError ? <ErrorState error={connect.error} /> : null}
            </CardContent>
        </Card>
    );
}

/**
 * Choose which Business Profile account and location the store front speaks
 * for. Connecting only proves who the Google user is; nothing syncs until both
 * are chosen.
 */
function GbpProfilePicker({ location, connection }: { location: Location; connection: GbpConnection }) {
    const { toast } = useToast();
    const accounts = useGbpAccounts(location.id, true);
    const select = useSelectGbpLocation();

    const [accountName, setAccountName] = useState<string | null>(connection.gbp_account_name);
    const [gbpLocationId, setGbpLocationId] = useState<string | null>(location.gbp_location_id);

    const accountId = accountName === null ? null : accountName.replace(/^accounts\//, '');
    const locations = useGbpLocations(location.id, accountId);

    function chooseAccount(value: string) {
        setAccountName(value);
        setGbpLocationId(null);
    }

    async function save() {
        if (accountName === null || gbpLocationId === null) {
            return;
        }

        try {
            await select.mutateAsync({
                location_id: location.id,
                gbp_account_name: accountName,
                gbp_location_id: gbpLocationId,
            });
            toast('連携するビジネスプロフィールを保存しました。');
        } catch (exception) {
            toast(errorMessage(exception, '保存に失敗しました。'), 'error');
        }
    }

    return (
        <div className="flex flex-col gap-3 border-t pt-4">
            <p className="text-sm font-medium">連携するビジネスプロフィール</p>

            {accounts.isPending ? (
                <LoadingState />
            ) : accounts.isError ? (
                <ErrorState error={accounts.error} />
            ) : accounts.data.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    このGoogleアカウントで管理しているビジネスプロフィールが見つかりません。
                </p>
            ) : (
                <>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="gbp-account">アカウント</Label>
                        <Select value={accountName ?? undefined} onValueChange={chooseAccount}>
                            <SelectTrigger id="gbp-account" className="w-full sm:w-96">
                                <SelectValue placeholder="アカウントを選択" />
                            </SelectTrigger>
                            <SelectContent>
                                {accounts.data.map((account) => (
                                    <SelectItem key={account.name} value={account.name}>
                                        {account.account_name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {accountId !== null ? (
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="gbp-location">ロケーション</Label>
                            {locations.isPending ? (
                                <LoadingState />
                            ) : locations.isError ? (
                                <ErrorState error={locations.error} />
                            ) : locations.data.length === 0 ? (
                                <p className="text-sm text-muted-foreground">このアカウントにロケーションがありません。</p>
                            ) : (
                                <Select value={gbpLocationId ?? undefined} onValueChange={setGbpLocationId}>
                                    <SelectTrigger id="gbp-location" className="w-full sm:w-96">
                                        <SelectValue placeholder="ロケーションを選択" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {locations.data.map((option) => (
                                            <SelectItem key={option.name} value={option.name}>
                                                {option.address === null
                                                    ? option.title
                                                    : `${option.title}（${option.address}）`}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>
                    ) : null}

                    <Button
                        className="w-fit"
                        disabled={accountName === null || gbpLocationId === null || select.isPending}
                        onClick={save}
                    >
                        保存
                    </Button>
                </>
            )}
        </div>
    );
}

function BillingPanel() {
    const organization = useCurrentOrganization();
    const { location } = useCurrentLocation();
    const reports = useReports(location?.id ?? null);
    const [error, setError] = useState<unknown>(null);
    const [busy, setBusy] = useState(false);
    const [choosingPlan, setChoosingPlan] = useState(false);

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

                    <div className="flex flex-wrap gap-2">
                        <Button className="w-fit" onClick={() => setChoosingPlan(true)}>
                            <ArrowUpCircle aria-hidden="true" />
                            プランをアップグレード
                        </Button>
                        <Button className="w-fit" variant="outline" disabled={busy} onClick={openPortal}>
                            <ExternalLink aria-hidden="true" />
                            請求ポータルを開く
                        </Button>
                    </div>

                    {error ? <ErrorState error={error} /> : null}
                </CardContent>
            </Card>

            <PlanPickerDialog
                open={choosingPlan}
                onOpenChange={setChoosingPlan}
                currentPlanCode={organization?.plan?.code ?? null}
            />

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

const PRODUCT_LABELS: Record<string, string> = { meo: 'MEO', ig: 'Instagram' };

function formatYen(amount: number): string {
    return `¥${amount.toLocaleString('ja-JP')}`;
}

function formatTerm(months: number): string {
    return months % 12 === 0 ? `${months / 12}年` : `${months}ヶ月`;
}

function PlanPickerDialog({
    open,
    onOpenChange,
    currentPlanCode,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    currentPlanCode: string | null;
}) {
    const plans = useBillingPlans(open);
    const checkout = useStartCheckout();
    const products = [...new Set((plans.data ?? []).map((plan) => plan.product))];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>プランを選択</DialogTitle>
                    <DialogDescription>お申し込み後、Stripe のお支払い画面に移動します。</DialogDescription>
                </DialogHeader>

                {plans.isPending ? (
                    <LoadingState />
                ) : plans.isError ? (
                    <ErrorState error={plans.error} />
                ) : products.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        現在お申し込みいただけるプランはありません。
                    </p>
                ) : (
                    products.map((product) => (
                        <section key={product} className="flex flex-col gap-3">
                            <h3 className="text-sm font-semibold">{PRODUCT_LABELS[product] ?? product}</h3>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {plans.data!
                                    .filter((plan) => plan.product === product)
                                    .map((plan) => (
                                        <PlanCard
                                            key={plan.code}
                                            plan={plan}
                                            current={plan.code === currentPlanCode}
                                            busy={checkout.isPending}
                                            onChoose={() => checkout.mutate(plan.code)}
                                        />
                                    ))}
                            </div>
                        </section>
                    ))
                )}

                {checkout.isError ? <ErrorState error={checkout.error} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function PlanCard({
    plan,
    current,
    busy,
    onChoose,
}: {
    plan: BillingPlan;
    current: boolean;
    busy: boolean;
    onChoose: () => void;
}) {
    return (
        <div className="flex flex-col gap-2 rounded-md border p-4 text-sm">
            <div className="flex items-center justify-between gap-2">
                <span className="font-semibold">{plan.name}</span>
                {current ? <Badge variant="success">ご利用中</Badge> : null}
            </div>
            <div>
                <span className="text-lg font-semibold">
                    {formatYen(plan.price)}
                </span>
                <span className="text-muted-foreground">{plan.interval === 'year' ? ' / 年' : ' / 月'}</span>
            </div>
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-muted-foreground">
                {plan.interval === 'year' ? (
                    <>
                        <dt>月額換算</dt>
                        <dd>{formatYen(plan.monthly_price)}</dd>
                    </>
                ) : null}
                <dt>契約期間</dt>
                <dd>{formatTerm(plan.billing_period_months)}</dd>
            </dl>
            {plan.phases > 1 ? (
                <p className="text-xs text-muted-foreground">
                    ※ 年払い×{plan.phases}回の自動更新です。途中解約は別途ご相談ください。
                </p>
            ) : null}
            <Button className="mt-auto" disabled={busy || current} onClick={onChoose}>
                このプランで申し込む
            </Button>
        </div>
    );
}
