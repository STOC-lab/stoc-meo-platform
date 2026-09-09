export type Role = 'viewer' | 'staff' | 'location_admin' | 'org_admin' | 'owner';

export type OrganizationStatus = 'active' | 'trialing' | 'past_due' | 'canceled' | 'suspended';

export interface User {
    id: number;
    name: string;
    email: string;
}

export interface PlanSummary {
    code: string;
    name: string;
    product: string;
}

export interface Organization {
    id: number;
    name: string;
    slug: string;
    status: OrganizationStatus;
    role: Role;
    plan: PlanSummary | null;
}

export interface Profile {
    user: User;
    organizations: Organization[];
}

/** An allowance the plan grants, as every list endpoint reports it. */
export interface Allowance {
    limit: number | null;
    used: number;
    remaining: number | null;
}

export interface Brand {
    id: number;
    name: string;
    slug: string | null;
    created_at: string | null;
}

export interface Location {
    id: number;
    name: string;
    brand: { id: number; name: string } | null;
    gbp_location_id: string | null;
    linked_to_gbp: boolean;
    website_url: string | null;
    phone: string | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    has_coordinates: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface RankingResultSummary {
    rank: number | null;
    ranked: boolean;
    search_url: string | null;
    provider: string;
    checked_at: string;
}

export interface RankHistoryPoint {
    date: string;
    rank: number | null;
    checked_at: string;
}

export interface Keyword {
    id: number;
    keyword: string;
    is_active: boolean;
    latest_result: RankingResultSummary | null;
    created_at: string | null;
    updated_at: string | null;
}

export type HeatmapGridSize = '5x5' | '7x7';

export type HeatmapStatus = 'pending' | 'running' | 'completed' | 'failed';

export interface HeatmapRun {
    id: number;
    keyword_id: number;
    keyword: string | null;
    grid_size: HeatmapGridSize;
    point_count: number;
    points_recorded: number;
    status: HeatmapStatus;
    status_label: string;
    failure_reason: string | null;
    scheduled_at: string | null;
    completed_at: string | null;
    created_at: string | null;
}

export interface HeatmapPoint {
    row: number;
    col: number;
    lat: number;
    lng: number;
    rank: number | null;
}

export interface HeatmapDetail extends HeatmapRun {
    centre: { lat: number | null; lng: number | null };
    points: HeatmapPoint[];
    grid: (number | null)[][];
}

export type AiReplyStatus = 'generating' | 'awaiting_approval' | 'approved' | 'published' | 'failed';

export interface Review {
    id: number;
    google_review_id: string;
    author_name: string | null;
    author_photo_url: string | null;
    rating: number | null;
    comment: string | null;
    reply: string | null;
    answered: boolean;
    replied_at: string | null;
    reviewed_at: string | null;
    ai_reply: string | null;
    ai_reply_status: AiReplyStatus | null;
    ai_reply_status_label: string | null;
    ai_reply_awaiting_approval: boolean;
    ai_reply_model: string | null;
    ai_reply_error: string | null;
    ai_reply_generated_at: string | null;
}

export type GbpPostStatus = 'draft' | 'publishing' | 'published' | 'failed';

export interface GbpPost {
    id: number;
    content: string;
    media_url: string | null;
    cta_type: string | null;
    cta_url: string | null;
    status: GbpPostStatus;
    status_label: string;
    gbp_post_id: string | null;
    failure_reason: string | null;
    published_at: string | null;
    created_at: string | null;
}

export type CampaignChannel = 'instagram' | 'gbp' | 'wordpress';

export type CampaignPostStatus =
    | 'pending'
    | 'ai_generating'
    | 'awaiting_approval'
    | 'approved'
    | 'publishing'
    | 'published'
    | 'failed'
    | 'cancelled';

export interface CampaignPost {
    id: number;
    channel: CampaignChannel;
    channel_label: string;
    status: CampaignPostStatus;
    status_label: string;
    ai_content: string | null;
    ai_hashtags: string[];
    platform_post_id: string | null;
    retry_count: number;
    max_retries: number;
    last_error: string | null;
    approved_at: string | null;
    published_at: string | null;
}

export interface Campaign {
    id: number;
    name: string;
    theme: string | null;
    source_image_path: string | null;
    campaign_type: 'manual' | 'scheduled' | 'recurring';
    campaign_type_label: string;
    status: 'draft' | 'active' | 'completed' | 'cancelled';
    status_label: string;
    scheduled_at: string | null;
    created_by: string | null;
    posts: CampaignPost[];
    created_at: string | null;
    updated_at: string | null;
}

export interface ScoreComponent {
    measured: boolean;
    score: number | null;
    weight: number;
    reason?: string;
    detail: Record<string, unknown>;
}

export interface MeoScore {
    score: number;
    breakdown: Record<string, ScoreComponent>;
    weakest: string | null;
    calculated_at: string;
}

export interface MeoScorePoint {
    date: string;
    score: number;
}

export interface Analysis {
    id: number;
    type: 'daily' | 'weekly';
    type_label: string;
    summary: string | null;
    highlights: string[];
    watch: string[];
    figures: Record<string, unknown> | null;
    period_start: string;
    period_end: string;
    model: string | null;
    created_at: string | null;
}

export type ProposalStatus = 'new' | 'in_progress' | 'done' | 'dismissed';

export interface Proposal {
    id: number;
    category: string;
    category_label: string;
    title: string;
    content: string;
    priority: 'high' | 'medium' | 'low';
    priority_label: string;
    status: ProposalStatus;
    status_label: string;
    score_at_generation: number | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface Alert {
    id: number;
    type: string;
    type_label: string;
    payload: Record<string, unknown>;
    is_read: boolean;
    keyword_id: number | null;
    created_at: string | null;
}

export interface Competitor {
    id: number;
    name: string;
    gbp_place_id: string | null;
    created_at: string | null;
}

export interface Member {
    id: number;
    name: string;
    email: string;
    role: Role;
    role_label?: string;
}

export interface GbpConnection {
    id: number;
    location_id: number;
    google_account_id: string;
    google_email: string | null;
    gbp_account_name: string | null;
    token_status: 'active' | 'expired' | 'revoked';
    token_status_label: string;
    needs_reconnection: boolean;
    token_expires_at: string | null;
    last_synced_at: string | null;
    connected_at: string | null;
}

export interface ReportSummary {
    id: number;
    period: string;
    period_label: string;
    status: string;
    status_label: string;
    downloadable: boolean;
    size: number | null;
    failure_reason: string | null;
    generated_at: string | null;
    created_at: string | null;
}
