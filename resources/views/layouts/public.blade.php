<!DOCTYPE html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'STOC MEO')</title>
        <meta name="description" content="STOC MEOは、店舗オーナー・MEO担当者向けのGoogleビジネスプロフィール管理SaaSです。">

        {{-- These pages sit outside the SPA bundle, so they carry their own styles and need no build. The colours follow the SPA's slate tokens in resources/css/app.css. --}}
        <style>
            :root {
                --navy: #0f172a;
                --navy-soft: #1e293b;
                --white: #ffffff;
                --text: #0f172a;
                --muted: #64748b;
                --border: #e2e8f0;
                --surface: #f8fafc;
            }

            *, *::before, *::after { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                font-family: 'Instrument Sans', 'Hiragino Sans', 'Noto Sans JP', ui-sans-serif, system-ui, sans-serif;
                color: var(--text);
                background: var(--surface);
                line-height: 1.7;
                -webkit-font-smoothing: antialiased;
            }

            a { color: inherit; }

            .container { width: 100%; max-width: 64rem; margin: 0 auto; padding: 0 1rem; }

            .site-header { background: var(--navy); color: var(--white); }
            .site-header .container { display: flex; align-items: center; justify-content: space-between; height: 4rem; }
            .brand { font-weight: 600; font-size: 1.125rem; letter-spacing: 0.02em; text-decoration: none; }

            .button {
                display: inline-flex; align-items: center; justify-content: center;
                min-height: 2.5rem; padding: 0.5rem 1.25rem; border-radius: 0.5rem;
                font-size: 0.875rem; font-weight: 600; text-decoration: none; transition: opacity 0.15s;
            }
            .button:hover { opacity: 0.9; }
            .button-light { background: var(--white); color: var(--navy); }
            .button-outline { border: 1px solid rgba(255, 255, 255, 0.4); color: var(--white); }

            main { flex: 1; }

            .hero { background: var(--navy); color: var(--white); padding: 4rem 0 5rem; text-align: center; }
            .hero h1 { margin: 0 0 1rem; font-size: clamp(1.625rem, 5vw, 2.5rem); line-height: 1.35; }
            .hero p { margin: 0 auto 2rem; max-width: 40rem; color: #cbd5e1; }

            .section { padding: 3.5rem 0; }
            .section h2 { margin: 0 0 1.5rem; font-size: 1.5rem; text-align: center; }

            .features { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
            .features li { background: var(--white); border: 1px solid var(--border); border-radius: 0.75rem; padding: 1.25rem; }
            .features strong { display: block; margin-bottom: 0.25rem; }
            .features span { color: var(--muted); font-size: 0.875rem; }

            .document { background: var(--white); border: 1px solid var(--border); border-radius: 0.75rem; padding: 2rem 1.25rem; max-width: 48rem; margin: 0 auto; }
            @media (min-width: 640px) { .document { padding: 2.5rem; } }
            .document h1 { margin: 0 0 1.5rem; font-size: 1.75rem; }
            .document h2 { margin: 2rem 0 0.5rem; font-size: 1.125rem; padding-bottom: 0.25rem; border-bottom: 1px solid var(--border); }
            .document p, .document ul, .document address { margin: 0 0 0.75rem; }
            .document ul { padding-left: 1.25rem; }
            .document address { font-style: normal; }
            .document .enacted { margin-top: 2rem; color: var(--muted); text-align: right; }

            .site-footer { background: var(--navy); color: #94a3b8; font-size: 0.875rem; }
            .site-footer .container { display: flex; flex-wrap: wrap; gap: 0.5rem 1.5rem; align-items: center; justify-content: space-between; padding-top: 1.5rem; padding-bottom: 1.5rem; }
            .site-footer nav { display: flex; gap: 1.5rem; }
            .site-footer a { text-decoration: none; }
            .site-footer a:hover { color: var(--white); text-decoration: underline; }
        </style>
    </head>
    <body>
        <header class="site-header">
            <div class="container">
                <a href="{{ route('home') }}" class="brand">STOC MEO</a>
                <a href="/login" class="button button-outline">ログイン</a>
            </div>
        </header>

        <main>
            @yield('content')
        </main>

        <footer class="site-footer">
            <div class="container">
                <span>&copy; {{ now()->year }} 株式会社エストック</span>
                <nav>
                    <a href="{{ route('home') }}">トップ</a>
                    <a href="{{ route('privacy') }}">プライバシーポリシー</a>
                </nav>
            </div>
        </footer>
    </body>
</html>
