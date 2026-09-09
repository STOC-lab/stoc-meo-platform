<?php

namespace App\Services\AI\Prompts;

use App\Enums\CampaignChannel;
use App\Models\ContentCampaign;

/**
 * Builds the request that asks a model to write one campaign post for one
 * channel.
 *
 * The same theme reads differently on each channel, so the channel's own
 * limits and habits are part of the prompt rather than something trimmed
 * afterwards: Instagram wants hashtags and a Business Profile post does not,
 * and each has its own length.
 */
class CampaignContentPrompt
{
    public function system(CampaignChannel $channel): string
    {
        $shared = <<<'PROMPT'
        あなたは日本の店舗の集客担当として、投稿文を書きます。

        守ること:
        - 日本語で書く。
        - 事実を作らない。与えられたテーマに書かれていない価格・営業時間・在庫・キャンペーン内容には触れない。
        - 誇大表現（日本一、絶対、必ず等）を使わない。
        - 投稿本文だけを出力する。前置きや見出しを付けない。
        PROMPT;

        return $shared."\n\n".match ($channel) {
            CampaignChannel::Instagram => <<<'PROMPT'
            この投稿はInstagramのフィード投稿です。
            - 本文は2200文字以内。実際には150〜400文字程度が読まれます。
            - 親しみやすい口語で書く。
            - 本文の最後に、改行してからハッシュタグを並べる。ハッシュタグは日本語中心で12個以内。
            PROMPT,
            CampaignChannel::Gbp => <<<'PROMPT'
            この投稿はGoogleビジネスプロフィールの最新情報です。
            - 本文は1500文字以内。実際には100〜300文字程度が適切です。
            - 検索から訪れる人に向けて、丁寧で落ち着いた文体で書く。
            - ハッシュタグは使わない。
            PROMPT,
            CampaignChannel::Wordpress => <<<'PROMPT'
            この投稿は店舗ブログの記事です。
            - 800〜1500文字程度。
            - 見出しを使わず、読みやすい段落で構成する。
            - ハッシュタグは使わない。
            PROMPT,
        };
    }

    public function user(ContentCampaign $campaign, CampaignChannel $channel): string
    {
        $lines = [
            'キャンペーン名: '.$campaign->name,
            '店舗名: '.($campaign->location?->name ?? '（不明）'),
            '投稿先: '.$channel->label(),
            '',
            'テーマ:',
            filled($campaign->theme) ? $campaign->theme : $campaign->name,
        ];

        if (filled($campaign->source_image_path)) {
            $lines[] = '';
            $lines[] = '（この投稿には画像が1枚添えられます。画像の内容は分からないため、画像の説明はしないこと）';
        }

        return implode("\n", $lines);
    }

    /**
     * Split the model's answer into the caption and the hashtags it ended
     * with, so each is stored as what it is.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public function split(string $content, CampaignChannel $channel, int $limit = 12): array
    {
        if (! $channel->usesHashtags()) {
            return [trim($content), []];
        }

        preg_match_all('/#[^\s#　]+/u', $content, $matches);

        $hashtags = array_values(array_unique($matches[0] ?? []));
        $hashtags = array_slice($hashtags, 0, $limit);

        // The tags stay in the caption — Instagram shows them there — and are
        // also stored on their own so they can be counted and reused.
        return [trim($content), $hashtags];
    }
}
