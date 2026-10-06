<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($layout === 'booking')
    <title>{{ $title }}</title>
    @else
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('ozepms.brand.name') }}</title>
    @endif
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', $entry])
</head>
<body class="layout-{{ $layout }}">
    <div id="root">
        @if ($layout === 'app')
            <div class="shell-placeholder"><aside></aside><header></header></div>
        @endif
    </div>
    <script type="application/json" id="oz-page" nonce="{{ Vite::cspNonce() }}">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
    <noscript><p style="padding:24px;font-family:sans-serif">{{ __('ui.javascript_required') }}</p></noscript>
</body>
</html>
