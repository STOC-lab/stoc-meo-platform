@extends('layouts.public')

@section('title', 'STOC MEO | Google ビジネスプロフィール管理')

@section('content')
    <section class="hero">
        <div class="container">
            <h1>Google ビジネスプロフィールを、<br>もっとスマートに管理する</h1>
            <p>
                STOC MEOは、店舗オーナー・MEO担当者向けのGoogleビジネスプロフィール管理SaaSです。
                検索順位の計測・ヒートマップ分析・口コミ管理・GBP投稿・AI改善提案を一元管理できます。
            </p>
            <a href="/login" class="button button-light">ログイン</a>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <h2>主な機能</h2>
            <ul class="features">
                <li><strong>検索順位モニタリング</strong><span>キーワード別・日次計測</span></li>
                <li><strong>ヒートマップ分析</strong><span>エリア別の検索露出を可視化</span></li>
                <li><strong>口コミ管理</strong><span>AIによる返信提案</span></li>
                <li><strong>GBP投稿</strong><span>投稿のスケジュール管理</span></li>
                <li><strong>AI Overview（AIO）対策</strong><span>AI検索での露出を強化</span></li>
                <li><strong>PDF レポート</strong><span>レポートを自動生成</span></li>
            </ul>
        </div>
    </section>
@endsection
