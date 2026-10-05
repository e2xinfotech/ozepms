<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') · {{ config('ozepms.brand.name', 'OzePMS') }}</title>
    <style>
        :root { --ink:#0b1b3f; --muted:#5b6b86; --brand:#1167f2; --bg:#f4f7fc; --line:#e3e9f3; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink);
               font:15px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width:min(480px, calc(100% - 32px)); background:#fff; border:1px solid var(--line); border-radius:16px;
                padding:40px 36px; text-align:center; box-shadow:0 12px 40px rgba(11,27,63,.06); }
        .logo { font-weight:800; font-size:28px; letter-spacing:-.5px; margin-bottom:28px; }
        .logo span { color:var(--brand); }
        .code { font-size:13px; font-weight:600; color:var(--brand); letter-spacing:.08em; text-transform:uppercase; }
        h1 { font-size:22px; margin:8px 0 8px; }
        p { color:var(--muted); margin:0 0 24px; }
        a.btn { display:inline-flex; height:40px; align-items:center; padding:0 18px; border-radius:10px; background:var(--brand);
                color:#fff; text-decoration:none; font-weight:600; }
        .ref { margin-top:20px; font-size:12px; color:var(--muted); }
    </style>
</head>
<body>
    <main class="card">
        <div class="logo">Oze<span>PMS</span></div>
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a class="btn" href="{{ url('/') }}">{{ __('errors.back_home') }}</a>
        @if (request()->attributes->get('request_id'))
            <div class="ref">{{ __('errors.reference') }}: {{ substr(request()->attributes->get('request_id'), -8) }}</div>
        @endif
    </main>
</body>
</html>
