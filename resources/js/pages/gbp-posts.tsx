import { useState } from 'react';
import { Check, Plus, Sparkles } from 'lucide-react';

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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import {
    useApproveCampaignPost,
    useCampaigns,
    useCreateCampaign,
    useCreateGbpPost,
    useGbpPosts,
    useGenerateCampaign,
} from '@/hooks/use-content';
import { useCurrentLocation } from '@/hooks/use-locations';
import { errorMessage } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import type { CampaignChannel, CampaignPostStatus, GbpPostStatus } from '@/types/api';

const postStatusVariants: Record<GbpPostStatus, 'default' | 'secondary' | 'success' | 'destructive'> = {
    draft: 'secondary',
    publishing: 'default',
    published: 'success',
    failed: 'destructive',
};

const campaignStatusVariants: Record<CampaignPostStatus, 'default' | 'secondary' | 'success' | 'destructive' | 'warning'> =
    {
        pending: 'secondary',
        ai_generating: 'default',
        awaiting_approval: 'warning',
        approved: 'default',
        publishing: 'default',
        published: 'success',
        failed: 'destructive',
        cancelled: 'secondary',
    };

export default function GbpPosts() {
    const { location, isPending: locationsPending } = useCurrentLocation();
    const locationId = location?.id ?? null;

    if (locationsPending) {
        return <LoadingState />;
    }

    if (location === null) {
        return (
            <div className="flex flex-col gap-6">
                <PageHeader title="投稿" />
                <EmptyState title="店舗が登録されていません" description="設定から店舗を追加してください。" />
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <PageHeader title="投稿" description={`${location.name} ・ Googleビジネスプロフィールとキャンペーン`} />

            <Tabs defaultValue="posts">
                <TabsList>
                    <TabsTrigger value="posts">GBP投稿</TabsTrigger>
                    <TabsTrigger value="campaigns">キャンペーン</TabsTrigger>
                </TabsList>

                <TabsContent value="posts">
                    <GbpPostsPanel locationId={locationId} />
                </TabsContent>

                <TabsContent value="campaigns">
                    <CampaignsPanel locationId={locationId} />
                </TabsContent>
            </Tabs>
        </div>
    );
}

function GbpPostsPanel({ locationId }: { locationId: number | null }) {
    const posts = useGbpPosts(locationId);
    const createPost = useCreateGbpPost(locationId);

    const [open, setOpen] = useState(false);
    const [content, setContent] = useState('');
    const [mediaUrl, setMediaUrl] = useState('');

    const allowance = posts.data?.allowance;

    async function submit(event: React.FormEvent) {
        event.preventDefault();

        try {
            await createPost.mutateAsync({
                content: content.trim(),
                media_url: mediaUrl.trim() === '' ? null : mediaUrl.trim(),
            });
            setContent('');
            setMediaUrl('');
            setOpen(false);
        } catch {
            // The dialog stays open and shows the reason below.
        }
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <CardTitle>GBP投稿</CardTitle>
                        <CardDescription>
                            {allowance
                                ? `今月 ${allowance.used} / ${allowance.limit ?? '無制限'} 件`
                                : 'Googleビジネスプロフィールの最新情報'}
                        </CardDescription>
                    </div>
                    <Button size="sm" onClick={() => setOpen(true)}>
                        <Plus aria-hidden="true" />
                        新規投稿
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                {posts.isPending ? (
                    <LoadingState />
                ) : posts.isError ? (
                    <ErrorState error={posts.error} />
                ) : (posts.data?.posts.length ?? 0) === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">まだ投稿がありません。</p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {posts.data!.posts.map((post) => (
                            <li key={post.id} className="rounded-md border p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Badge variant={postStatusVariants[post.status]}>{post.status_label}</Badge>
                                    <span className="text-xs text-muted-foreground">
                                        {formatDateTime(post.published_at ?? post.created_at)}
                                    </span>
                                </div>
                                <p className="mt-2 text-sm whitespace-pre-wrap">{post.content}</p>
                                {post.failure_reason ? (
                                    <p className="mt-2 text-sm text-destructive">{post.failure_reason}</p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>GBPに投稿</DialogTitle>
                            <DialogDescription>
                                Googleビジネスプロフィールの「最新情報」として公開されます。
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="post-content">本文</Label>
                            <Textarea
                                id="post-content"
                                value={content}
                                onChange={(event) => setContent(event.target.value)}
                                rows={6}
                                maxLength={1500}
                                required
                                autoFocus
                            />
                            <p className="text-xs text-muted-foreground">{content.length} / 1500</p>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="post-media">画像URL（任意）</Label>
                            <Input
                                id="post-media"
                                type="url"
                                value={mediaUrl}
                                onChange={(event) => setMediaUrl(event.target.value)}
                                placeholder="https://…"
                            />
                        </div>

                        {createPost.isError ? (
                            <p className="text-sm text-destructive">{errorMessage(createPost.error)}</p>
                        ) : null}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                キャンセル
                            </Button>
                            <Button type="submit" disabled={createPost.isPending}>
                                投稿する
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/**
 * Campaigns turn one theme into a post per channel. Each channel is approved
 * on its own, because each publishes on its own.
 */
function CampaignsPanel({ locationId }: { locationId: number | null }) {
    const campaigns = useCampaigns(locationId);
    const createCampaign = useCreateCampaign(locationId);
    const generate = useGenerateCampaign(locationId);
    const approve = useApproveCampaignPost(locationId);

    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [theme, setTheme] = useState('');
    const [imageUrl, setImageUrl] = useState('');
    const [channels, setChannels] = useState<CampaignChannel[]>(['gbp']);

    function toggleChannel(channel: CampaignChannel) {
        setChannels((current) =>
            current.includes(channel) ? current.filter((entry) => entry !== channel) : [...current, channel],
        );
    }

    async function submit(event: React.FormEvent) {
        event.preventDefault();

        try {
            await createCampaign.mutateAsync({
                name: name.trim(),
                theme: theme.trim() === '' ? null : theme.trim(),
                source_image_path: imageUrl.trim() === '' ? null : imageUrl.trim(),
                campaign_type: 'manual',
                channels,
            });
            setName('');
            setTheme('');
            setImageUrl('');
            setOpen(false);
        } catch {
            // The dialog stays open and shows the reason below.
        }
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <CardTitle>コンテンツキャンペーン</CardTitle>
                        <CardDescription>1つのテーマから各チャネルの投稿文をAIが作成します</CardDescription>
                    </div>
                    <Button size="sm" onClick={() => setOpen(true)}>
                        <Plus aria-hidden="true" />
                        キャンペーン作成
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                {campaigns.isPending ? (
                    <LoadingState />
                ) : campaigns.isError ? (
                    <ErrorState error={campaigns.error} />
                ) : (campaigns.data?.length ?? 0) === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        まだキャンペーンがありません。
                    </p>
                ) : (
                    <div className="flex flex-col gap-4">
                        {campaigns.data!.map((campaign) => (
                            <div key={campaign.id} className="rounded-md border p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="font-medium">{campaign.name}</p>
                                        <p className="text-xs text-muted-foreground">{campaign.theme}</p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline">{campaign.status_label}</Badge>
                                        {campaign.posts.some(
                                            (post) => post.status === 'pending' || post.status === 'failed',
                                        ) ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={generate.isPending}
                                                onClick={() => generate.mutate(campaign.id)}
                                            >
                                                <Sparkles aria-hidden="true" />
                                                AI生成
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>

                                <ul className="mt-3 flex flex-col gap-2">
                                    {campaign.posts.map((post) => (
                                        <li key={post.id} className="rounded-md border bg-muted/30 p-3 text-sm">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-medium">{post.channel_label}</span>
                                                <Badge variant={campaignStatusVariants[post.status]}>
                                                    {post.status_label}
                                                </Badge>
                                            </div>
                                            {post.ai_content ? (
                                                <p className="mt-2 whitespace-pre-wrap">{post.ai_content}</p>
                                            ) : null}
                                            {post.last_error ? (
                                                <p className="mt-2 text-destructive">{post.last_error}</p>
                                            ) : null}
                                            {post.status === 'awaiting_approval' ? (
                                                <Button
                                                    size="sm"
                                                    className="mt-2"
                                                    disabled={approve.isPending}
                                                    onClick={() =>
                                                        approve.mutate({
                                                            campaignId: campaign.id,
                                                            postId: post.id,
                                                        })
                                                    }
                                                >
                                                    <Check aria-hidden="true" />
                                                    承認して公開
                                                </Button>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}

                {generate.isError ? <ErrorState error={generate.error} className="mt-3" /> : null}
                {approve.isError ? <ErrorState error={approve.error} className="mt-3" /> : null}
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>キャンペーンを作成</DialogTitle>
                            <DialogDescription>
                                テーマを書くと、選んだチャネルごとにAIが投稿文を作成します。
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="campaign-name">キャンペーン名</Label>
                            <Input
                                id="campaign-name"
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                required
                                autoFocus
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="campaign-theme">テーマ</Label>
                            <Textarea
                                id="campaign-theme"
                                value={theme}
                                onChange={(event) => setTheme(event.target.value)}
                                rows={4}
                                placeholder="秋限定メニューの紹介"
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="campaign-image">画像URL（Instagramには必須）</Label>
                            <Input
                                id="campaign-image"
                                type="url"
                                value={imageUrl}
                                onChange={(event) => setImageUrl(event.target.value)}
                                placeholder="https://…"
                            />
                        </div>

                        <fieldset className="flex flex-col gap-2">
                            <legend className="text-sm font-medium">投稿先</legend>
                            <div className="flex flex-wrap gap-2">
                                {(
                                    [
                                        ['gbp', 'Googleビジネスプロフィール'],
                                        ['instagram', 'Instagram'],
                                    ] as const
                                ).map(([value, label]) => (
                                    <Button
                                        key={value}
                                        type="button"
                                        size="sm"
                                        variant={channels.includes(value) ? 'default' : 'outline'}
                                        onClick={() => toggleChannel(value)}
                                    >
                                        {label}
                                    </Button>
                                ))}
                            </div>
                        </fieldset>

                        {createCampaign.isError ? (
                            <p className="text-sm text-destructive">{errorMessage(createCampaign.error)}</p>
                        ) : null}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                キャンセル
                            </Button>
                            <Button type="submit" disabled={createCampaign.isPending || channels.length === 0}>
                                作成
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
