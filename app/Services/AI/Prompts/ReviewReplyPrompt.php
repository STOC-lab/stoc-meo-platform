<?php

namespace App\Services\AI\Prompts;

use App\Models\Review;

/**
 * Builds the request that asks a model to draft a reply to one review.
 *
 * The rules here are the ones a shop owner would want held to: answer in the
 * language the review was written in, never invent a fact about the business,
 * never promise anything, and stay short enough that it reads as a person
 * rather than a form letter. A poor review is answered without arguing.
 */
class ReviewReplyPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
        あなたは日本の店舗オーナーに代わって、Googleビジネスプロフィールに届いた口コミへの返信文を書きます。

        守ること:
        - 口コミと同じ言語で書く。日本語の口コミには日本語で返す。
        - 120〜200文字程度。長い返信は定型文に見えます。
        - 事実を作らない。口コミに書かれていない出来事・メニュー・キャンペーンには触れない。
        - 補償・返金・再来店特典などを約束しない。
        - 低評価には反論せず、指摘を受け止め、改善に取り組む姿勢を簡潔に示す。
        - 投稿者名が分かる場合のみ、冒頭で自然に触れる。
        - 署名・店舗名・URL・絵文字・ハッシュタグは付けない。
        - 返信文だけを出力する。前置きや説明を付けない。
        PROMPT;
    }

    public function user(Review $review): string
    {
        $lines = [
            '店舗名: '.($review->location?->name ?? '（不明）'),
            '評価: '.($review->rating === null ? '（評価なし）' : $review->rating.' / 5'),
            '投稿者: '.($review->author_name ?? '（匿名）'),
            '',
            '口コミ本文:',
            filled($review->comment) ? $review->comment : '（本文なし。評価のみの投稿です）',
        ];

        return implode("\n", $lines);
    }
}
