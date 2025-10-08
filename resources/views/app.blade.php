<!doctype html>
<html lang="fa" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  {{-- عنوان برنامه از تنظیمات اپ --}}
  <title>{{ config('app.name', 'Panel') }}</title>

  {{-- CSRF (برای استفاده‌های آینده) --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">

  {{-- Vite entry --}}
  @vite('resources/js/app.js')

  {{-- Icons & PWA - همگی از public/ خوانده می‌شوند --}}
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <link rel="icon" type="image/png" sizes="16x16"  href="{{ asset('favicon-16x16.png') }}">
  <link rel="icon" type="image/png" sizes="32x32"  href="{{ asset('favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="48x48"  href="{{ asset('favicon-48x48.png') }}">
  <link rel="icon" type="image/png" sizes="64x64"  href="{{ asset('favicon-64x64.png') }}">
  <link rel="icon" type="image/png" sizes="96x96"  href="{{ asset('favicon-96x96.png') }}">
  <link rel="icon" type="image/png" sizes="128x128" href="{{ asset('favicon-128x128.png') }}">
  <link rel="icon" type="image/png" sizes="256x256" href="{{ asset('favicon-256x256.png') }}">
  <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('android-chrome-192x192.png') }}">
  <link rel="icon" type="image/png" sizes="384x384" href="{{ asset('android-chrome-384x384.png') }}">
  <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('android-chrome-512x512.png') }}">

  {{-- Apple Touch Icon --}}
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

  {{-- Web App Manifest --}}
  <link rel="manifest" href="{{ asset('site.webmanifest') }}">

  {{-- Theme Color (برای PWA / مرورگرها) --}}
  <meta name="theme-color" content="#0a0a0a">
</head>
<body>
  <noscript>برای استفاده از این برنامه لازم است JavaScript مرورگر شما فعال باشد.</noscript>

  <div id="app"></div>

  {{-- Bootstrap JSON برای Vue --}}
  <script id="__BOOTSTRAP__" type="application/json">
    {!! json_encode($bootstrap ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
  </script>
</body>
</html>
