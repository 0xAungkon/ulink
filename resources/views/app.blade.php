<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ULink keeps one public link stable while your Cloudflare Tunnel URL changes.">
    <title>ULink — Updateable links</title>
    <script>window.ULINK_CONFIG = { adminPath: @json('/'.trim((string) config('ulink.admin_path', 'admin'), '/')) };</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
