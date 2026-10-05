<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>workbench: second page</title>

    {{-- No @webmcp here: this page has no server manifest, only a Livewire component. --}}
    <script type="module" src="/vendor/webmcp/webmcp-livewire.js"></script>
    <script type="module" src="/vendor/webmcp/webmcp-alpine.js"></script>
</head>
<body>
    <h1>Second page</h1>
    <p><a href="/" wire:navigate id="to-first">Back to the shop page</a></p>
    <livewire:cart />
</body>
</html>
