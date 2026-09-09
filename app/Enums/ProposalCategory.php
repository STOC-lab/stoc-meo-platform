<?php

namespace App\Enums;

/**
 * What part of the store front's presence a proposal is about. The categories
 * mirror the four things the MEO score is made of, so a proposal can be traced
 * back to the number that prompted it.
 */
enum ProposalCategory: string
{
    case Ranking = 'ranking';
    case Heatmap = 'heatmap';
    case Reviews = 'reviews';
    case Profile = 'profile';
    case Content = 'content';

    public function label(): string
    {
        return match ($this) {
            self::Ranking => '検索順位',
            self::Heatmap => 'エリア分析',
            self::Reviews => '口コミ',
            self::Profile => 'プロフィール',
            self::Content => '情報発信',
        };
    }
}
