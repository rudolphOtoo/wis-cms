{{-- Profile branding injected into the page shell. Rendered by both the SPA entry (welcome.blade.php) and the server-rendered dashboard layout, then read by the React app (Login / Sidebar / Portal) before first paint. Expected data: appTitle, appName, tagline, logo, favicon (strings). --}}
@php
    $appMeta = json_encode([
        'appTitle' => $appTitle ?? '',
        'appName'  => $appName ?? '',
        'tagline'  => $tagline ?? '',
        'logo'     => $logo ?? '',
        'logoWebp' => $logoWebp ?? $logo ?? '',
        'favicon'  => $favicon ?? '',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
@endphp
<script>
    window.APP_META = {!! $appMeta !!};
</script>
