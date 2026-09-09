<?php

namespace App\Services\AI\Prompts;

use App\Models\ImprovementProposal;
use App\Models\Location;
use App\Models\MeoScore;
use Illuminate\Support\Collection;

/**
 * Builds the request that asks a model for concrete things a store front
 * should do next.
 *
 * The model is given the score's own working rather than prose, so the advice
 * is anchored to what was actually measured. What has already been suggested
 * is passed in too — a weekly sweep that keeps repeating itself stops being
 * read.
 */
class ImprovementProposalPrompt
{
    /**
     * How many proposals one run should produce.
     */
    public const COUNT = 3;

    public function system(): string
    {
        return <<<'PROMPT'
        あなたは日本のMEO（Googleマップ検索対策）コンサルタントです。店舗の計測データをもとに、次に取り組むべき改善策を提案します。

        守ること:
        - 与えられた数値だけを根拠にする。データにない事実（客層・立地・競合の状況など）を推測して書かない。
        - 店舗側が自分で実行できる具体的な行動を書く。「認知度を上げる」のような抽象的な提案はしない。
        - すでに提案済みの内容は繰り返さない。
        - 順位保証や効果の断定をしない。
        - 出力は必ず次のJSON配列のみ。前後に説明を付けない。

        [
          {
            "category": "ranking" | "heatmap" | "reviews" | "profile" | "content",
            "priority": "high" | "medium" | "low",
            "title": "30文字以内の要約",
            "content": "100〜200文字の具体的な実行内容"
          }
        ]
        PROMPT;
    }

    /**
     * @param  Collection<int, ImprovementProposal>  $existing
     */
    public function user(Location $location, MeoScore $score, Collection $existing): string
    {
        $lines = [
            '店舗名: '.$location->name,
            'MEOスコア: '.$score->score.' / 100',
            '',
            '内訳:',
        ];

        foreach ($score->breakdown ?? [] as $key => $component) {
            if (($component['measured'] ?? false) !== true) {
                $lines[] = "- {$key}: 計測なし（".($component['reason'] ?? '理由不明').'）';

                continue;
            }

            $lines[] = "- {$key}: ".$component['score'].' 点';

            foreach ($component['detail'] ?? [] as $name => $value) {
                if (is_scalar($value) || $value === null) {
                    $lines[] = '    '.$name.': '.$this->readable($value);
                }
            }
        }

        if ($existing->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'すでに提案済み（繰り返さないこと）:';

            foreach ($existing as $proposal) {
                $lines[] = '- '.$proposal->title;
            }
        }

        $lines[] = '';
        $lines[] = '上記をもとに、優先度の高い改善策を'.self::COUNT.'件、JSON配列で出力してください。';

        return implode("\n", $lines);
    }

    protected function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '不明',
            is_bool($value) => $value ? 'あり' : 'なし',
            default => (string) $value,
        };
    }
}
