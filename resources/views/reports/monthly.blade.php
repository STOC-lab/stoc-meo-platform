<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>{{ $organization['name'] }} MEOレポート {{ $period['label'] }}</title>
    <style>
        @if ($font['file'])
        @font-face {
            font-family: "{{ $font['family'] }}";
            font-style: normal;
            font-weight: normal;
            src: url("{{ $font['file'] }}") format("truetype");
        }
        @endif

        @page { margin: 18mm 14mm; }

        body {
            font-family: "{{ $font['family'] }}", sans-serif;
            font-size: 10pt;
            color: #1f2933;
            line-height: 1.6;
        }

        h1 { font-size: 18pt; margin: 0 0 2mm; }
        h2 { font-size: 13pt; margin: 8mm 0 2mm; border-bottom: 1.5pt solid #1f2933; padding-bottom: 1mm; }
        h3 { font-size: 11pt; margin: 5mm 0 1.5mm; color: #3e4c59; }

        .subtitle { color: #616e7c; font-size: 9pt; margin: 0 0 6mm; }
        .totals { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
        .totals td { border: 0.5pt solid #cbd2d9; padding: 2mm; text-align: center; }
        .totals .value { font-size: 15pt; font-weight: bold; }
        .totals .label { font-size: 8pt; color: #616e7c; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
        table.data th, table.data td { border: 0.5pt solid #cbd2d9; padding: 1.5mm 2mm; }
        table.data th { background: #f5f7fa; font-size: 9pt; text-align: left; }
        table.data td { font-size: 9pt; }
        table.data td.num { text-align: right; }

        .muted { color: #7b8794; font-size: 9pt; }
        .note { color: #7b8794; font-size: 8pt; font-style: italic; }
        .location { page-break-inside: avoid; margin-bottom: 6mm; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>

<h1>{{ $organization['name'] }} MEOレポート</h1>
<p class="subtitle">
    対象期間: {{ $period['label'] }}（{{ $period['start'] }} 〜 {{ $period['end'] }}）<br>
    作成日時: {{ $generated_at }}
</p>

<table class="totals">
    <tr>
        <td><div class="value">{{ $totals['locations'] }}</div><div class="label">店舗</div></td>
        <td><div class="value">{{ $totals['keywords'] }}</div><div class="label">キーワード</div></td>
        <td><div class="value">{{ $totals['heatmaps'] }}</div><div class="label">ヒートマップ</div></td>
        <td><div class="value">{{ $totals['competitors'] }}</div><div class="label">競合</div></td>
        <td><div class="value">{{ $totals['alerts'] }}</div><div class="label">順位急落</div></td>
    </tr>
</table>

@forelse ($locations as $location)
    <div class="location">
        <h2>{{ $location['name'] }}</h2>
        @if ($location['address'])
            <p class="muted">{{ $location['address'] }}</p>
        @endif

        <h3>順位推移サマリー</h3>
        @forelse ($location['rankings'] as $row)
            @if ($loop->first)
                <table class="data">
                    <tr>
                        <th>キーワード</th>
                        <th>計測回数</th>
                        <th>最高</th>
                        <th>最低</th>
                        <th>平均</th>
                        <th>圏外</th>
                    </tr>
            @endif
                    <tr>
                        <td>{{ $row['keyword'] }}@unless ($row['is_active'])<span class="muted">（停止中）</span>@endunless</td>
                        <td class="num">{{ $row['checks'] }}</td>
                        <td class="num">{{ $row['best'] ?? '—' }}</td>
                        <td class="num">{{ $row['worst'] ?? '—' }}</td>
                        <td class="num">{{ $row['average'] ?? '—' }}</td>
                        <td class="num">{{ $row['missing'] }}</td>
                    </tr>
            @if ($loop->last)
                </table>
                <p class="note">最高・最低・平均は、順位が取得できた計測のみを対象としています。</p>
            @endif
        @empty
            <p class="muted">この期間に計測されたキーワードはありません。</p>
        @endforelse

        <h3>ヒートマップ実行結果</h3>
        @if ($location['heatmaps']['total'] > 0)
            <p class="muted">
                実行 {{ $location['heatmaps']['total'] }} 件（完了 {{ $location['heatmaps']['completed'] }} 件 /
                失敗 {{ $location['heatmaps']['failed'] }} 件）
            </p>
            <table class="data">
                <tr>
                    <th>キーワード</th>
                    <th>グリッド</th>
                    <th>状態</th>
                    <th>完了日時</th>
                    <th>掲載地点</th>
                    <th>最高</th>
                    <th>平均</th>
                </tr>
                @foreach ($location['heatmaps']['runs'] as $run)
                    <tr>
                        <td>{{ $run['keyword'] ?? '—' }}</td>
                        <td>{{ $run['grid_size'] }}</td>
                        <td>{{ $run['status'] }}</td>
                        <td>{{ $run['completed_at'] ?? '—' }}</td>
                        <td class="num">
                            @if ($run['total_points'])
                                {{ $run['ranked_points'] }} / {{ $run['total_points'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="num">{{ $run['best'] ?? '—' }}</td>
                        <td class="num">{{ $run['average'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="muted">この期間に実行されたヒートマップはありません。</p>
        @endif

        <h3>競合比較サマリー</h3>
        <p class="muted">
            自店舗の期間平均順位:
            {{ $location['competitors']['own_average'] ?? '—' }}
        </p>
        @if (count($location['competitors']['tracked']) > 0)
            <table class="data">
                <tr>
                    <th>競合店舗</th>
                    <th>Googleビジネスプロフィール</th>
                </tr>
                @foreach ($location['competitors']['tracked'] as $competitor)
                    <tr>
                        <td>{{ $competitor['name'] }}</td>
                        <td>{{ $competitor['gbp_place_id'] ?? '未登録' }}</td>
                    </tr>
                @endforeach
            </table>
            <p class="note">競合店舗の順位は現在計測対象外のため、本レポートには自店舗の順位のみを掲載しています。</p>
        @else
            <p class="muted">登録されている競合店舗はありません。</p>
        @endif

        <h3>順位急落アラート</h3>
        @if (count($location['alerts']) > 0)
            <table class="data">
                <tr>
                    <th>発生日</th>
                    <th>キーワード</th>
                    <th>変動前</th>
                    <th>変動後</th>
                    <th>下落幅</th>
                </tr>
                @foreach ($location['alerts'] as $alert)
                    <tr>
                        <td>{{ $alert['raised_at'] ?? '—' }}</td>
                        <td>{{ $alert['keyword'] ?? '—' }}</td>
                        <td class="num">{{ $alert['previous_rank'] ?? '—' }}</td>
                        <td class="num">{{ $alert['current_rank'] ?? '圏外' }}</td>
                        <td class="num">{{ $alert['drop'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="muted">この期間に順位急落は検知されていません。</p>
        @endif
    </div>

    @unless ($loop->last)
        <div class="page-break"></div>
    @endunless
@empty
    <p class="muted">対象となる店舗がありません。</p>
@endforelse

</body>
</html>
