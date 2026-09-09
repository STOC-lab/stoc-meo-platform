<?php

namespace App\Enums;

/**
 * Where a campaign post is published. Each channel is published by its own
 * job, so one channel failing leaves the others alone.
 */
enum CampaignChannel: string
{
    case Instagram = 'instagram';
    case Gbp = 'gbp';
    case Wordpress = 'wordpress';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Gbp => 'Googleビジネスプロフィール',
            self::Wordpress => 'WordPress',
        };
    }

    /**
     * How long the channel lets a caption run. Generation is asked to stay
     * inside this so a post is not cut short at publishing time.
     */
    public function contentLimit(): int
    {
        return match ($this) {
            self::Instagram => 2200,
            self::Gbp => 1500,
            self::Wordpress => 20000,
        };
    }

    /**
     * Whether hashtags belong on this channel. They read as noise on a
     * Business Profile post and are what Instagram runs on.
     */
    public function usesHashtags(): bool
    {
        return $this === self::Instagram;
    }
}
