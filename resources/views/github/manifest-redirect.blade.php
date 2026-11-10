<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Redirecting to GitHub…</title>
    <style>body{font:16px system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;color:#14171c}button{font:inherit;padding:.6rem 1rem;border-radius:10px;border:1px solid #c9ced6;background:#14171c;color:#fff;cursor:pointer}</style>
</head>
<body>
    {{-- GitHub requires the manifest as a POST form field; this page submits it immediately. --}}
    <form id="manifest-form" method="POST" action="{{ $action }}">
        <input type="hidden" name="manifest" value="{{ $manifest }}">
        <noscript><p>JavaScript is off. Press the button to continue to GitHub.</p></noscript>
        <p>Taking you to GitHub to create the app…</p>
        <button type="submit">Continue to GitHub</button>
    </form>
    <script nonce="{{ Vite::cspNonce() }}">document.getElementById('manifest-form').submit();</script>
</body>
</html>
