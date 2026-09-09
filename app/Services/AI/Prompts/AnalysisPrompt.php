<?php

namespace App\Services\AI\Prompts;

use App\Enums\AnalysisType;
use App\Models\Location;

/**
 * Builds the request that asks a model to read a period's figures back to the
 * shop owner.
 *
 * The daily and weekly analyses differ in what they can usefully say: a day is
 * too short to call a trend, so the daily one reports what moved and the
 * weekly one is allowed to draw a line through it.
 */
class AnalysisPrompt
{
    public function system(AnalysisType $type): string
    {
        $shared = <<<'PROMPT'
        あなたは日本のMEOコンサルタントとして、店舗の計測データを店舗オーナーに向けて解説します。

        守ること:
        - 与えられた数値だけを根拠にする。データにない事実を推測しない。
        - 専門用語を避け、店舗オーナーが読んで分かる言葉で書く。
        - 順位保証や効果の断定をしない。
        - 出力は必ず次のJSONのみ。前後に説明を付けない。

        {
          "summary": "全体の要約。100〜150文字",
          "highlights": ["良かった点。各50文字以内。最大3件"],
          "watch": ["注意すべき点。各50文字以内。最大3件"]
        }
        PROMPT;

        return $shared."\n\n".match ($type) {
            AnalysisType::Daily => '対象は直近1日です。1日の変動は誤差の範囲であることも多いため、傾向を断定せず、事実として動いた点を伝えてください。',
            AnalysisType::Weekly => '対象は直近1週間です。週単位の変化として読み取れる傾向があれば、その根拠となる数値とともに伝えてください。',
        };
    }

    /**
     * @param  array<string, mixed>  $figures
     */
    public function user(Location $location, AnalysisType $type, array $figures): string
    {
        $lines = [
            '店舗名: '.$location->name,
            '対象期間: '.$figures['period']['start'].' 〜 '.$figures['period']['end'],
            '',
            'MEOスコア: '.($figures['score'] === null ? '計測なし' : $figures['score'].' / 100'),
        ];

        if ($figures['score_change'] !== null) {
            $lines[] = '前期間比: '.($figures['score_change'] > 0 ? '+' : '').$figures['score_change'];
        }

        $lines[] = '';
        $lines[] = '順位:';
        $lines[] = '- 計測キーワード数: '.$figures['ranking']['keywords'];
        $lines[] = '- 平均順位: '.($figures['ranking']['average_rank'] ?? '圏外のみ');
        $lines[] = '- 圏外だった計測: '.$figures['ranking']['unranked'].' 件';
        $lines[] = '- 急落アラート: '.$figures['alerts'].' 件';

        $lines[] = '';
        $lines[] = '口コミ:';
        $lines[] = '- 新着: '.$figures['reviews']['new'].' 件';
        $lines[] = '- 平均評価: '.($figures['reviews']['average_rating'] ?? 'なし');
        $lines[] = '- 未返信: '.$figures['reviews']['unanswered'].' 件';

        $lines[] = '';
        $lines[] = '発信:';
        $lines[] = '- GBP投稿: '.$figures['posts']['gbp'].' 件';

        $lines[] = '';
        $lines[] = '上記をもとに、'.$type->label().'をJSONで出力してください。';

        return implode("\n", $lines);
    }
}
