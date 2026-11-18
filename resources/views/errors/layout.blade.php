<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') · @yield('title') · TriProof AI</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #0b1526;
            --muted: #64748b;
            --brand: #087f5b;
            --brand-soft: #e8f7f1;
            --line: #e2e8f0;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans Bengali", sans-serif;
            background:
                radial-gradient(circle at 15% 10%, #dff5ec 0, transparent 36%),
                radial-gradient(circle at 88% 88%, #e7eef9 0, transparent 34%),
                #f6f8fb;
        }

        .shell {
            width: 100%;
            max-width: 520px;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 18px;
            text-decoration: none;
            color: var(--ink);
        }

        .mark {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            background: var(--ink);
            color: #6ee7b7;
        }

        .brand strong {
            font-size: 15px;
            letter-spacing: -0.2px;
        }

        .card {
            overflow: hidden;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 24px 70px -32px rgba(15, 23, 42, 0.3);
            backdrop-filter: blur(8px);
        }

        .top {
            padding: 34px 28px 26px;
            text-align: center;
            border-bottom: 1px solid var(--line);
        }

        .code {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            background: var(--brand-soft);
            color: var(--brand);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.14em;
        }

        h1 {
            margin: 14px 0 8px;
            font-size: clamp(24px, 5vw, 30px);
            line-height: 1.15;
            letter-spacing: -0.7px;
        }

        p {
            max-width: 390px;
            margin: 0 auto;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .bottom {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            padding: 20px 24px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 38px;
            padding: 8px 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            color: var(--ink);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-primary {
            border-color: var(--ink);
            background: var(--ink);
            color: #fff;
        }

        .hint {
            margin-top: 14px;
            color: #94a3b8;
            font-size: 10px;
            text-align: center;
        }

        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    </style>
</head>
<body>
    <main class="shell">
        <a href="{{ url('/') }}" class="brand">
            <span class="mark">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3 4.5 6v5.2c0 4.8 3.1 8.2 7.5 9.8 4.4-1.6 7.5-5 7.5-9.8V6L12 3Z" />
                    <path d="m8.7 12.1 2.2 2.2 4.4-4.5" />
                </svg>
            </span>
            <strong>TriProof AI</strong>
        </a>

        <div class="card">
            <div class="top">
                <span class="code">ERROR @yield('code')</span>
                <h1>@yield('title')</h1>
                <p>@yield('message')</p>
            </div>
            <div class="bottom">
                <a href="{{ url('/dashboard') }}" class="btn btn-primary">Dashboard</a>
                <a href="{{ url('/') }}" class="btn">Home</a>
                <a href="javascript:history.back()" class="btn">Go back</a>
            </div>
        </div>

        <p class="hint">If this keeps happening, contact your TriProof administrator and share error code <span class="mono">@yield('code')</span>.</p>
    </main>
</body>
</html>
