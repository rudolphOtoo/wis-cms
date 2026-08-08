<!DOCTYPE html>
<html lang="en">
<head>
    @php
        $appTitle = \App\Diocese\Diocese::string('app.title', 'WIS-CMS');
        $appName  = \App\Diocese\Diocese::string('app_name', 'WIS-CMS');
        $tagline  = \App\Diocese\Diocese::string('tagline', '');
        $logo     = \App\Diocese\Diocese::string('logo', '/images/wis-logo.png');
        $logoWebp = \App\Diocese\Diocese::string('logo_webp', $logo);
        $favicon  = \App\Diocese\Diocese::string('favicon', '/favicon.png');
        $favFile  = public_path(ltrim($favicon, '/'));
        $favVer   = is_file($favFile) ? filemtime($favFile) : 0;
    @endphp

    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $appTitle }} — {{ $appName }}</title>

    {{-- Favicon — auto-busts cache when the profile's file changes --}}
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset($favicon) }}?v={{ $favVer }}" />
    <link rel="icon" type="image/x-icon" sizes="16x16 32x32 48x48" href="{{ asset($favicon) }}?v={{ $favVer }}" />
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset($favicon) }}?v={{ $favVer }}" />

    {{-- Profile branding for the SPA — server-injected, available before
        React mounts so Login / Sidebar / Portal never flash the wrong logo. --}}
    @include('partials.app-meta', [
        'appTitle' => $appTitle,
        'appName'  => $appName,
        'tagline'  => $tagline,
        'logo'     => $logo,
        'logoWebp' => $logoWebp,
        'favicon'  => $favicon,
    ])

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Playfair+Display:wght@400;600;700&display=swap"
          media="print" onload="this.media='all'" />
    <noscript>
        <link rel="stylesheet"
              href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Playfair+Display:wght@400;600;700&display=swap" />
    </noscript>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.jsx'])
    <style>
        .boot-spinner {
            display: flex; align-items: center; justify-content: center;
            height: 100vh; width: 100vw;
            background: #F8F9FC;
        }
        .boot-spinner svg {
            animation: boot-pulse 1.5s ease-in-out infinite;
        }
        @keyframes boot-pulse {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.08); }
        }
    </style>
</head>
<body>
    <div id="root">
        <div class="boot-spinner">
            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="4" y="4" width="40" height="40" rx="8" fill="#1B3A6B" opacity="0.15" />
                <path d="M24 10L10 18v12l14 8 14-8V18L24 10z" fill="#1B3A6B" opacity="0.6"/>
                <path d="M24 18l-8 4v6l8 4 8-4v-6l-8-4z" fill="#C9A84C"/>
                <rect x="22" y="26" width="4" height="6" rx="1" fill="#1B3A6B" opacity="0.8"/>
                <circle cx="24" cy="22" r="3" fill="#1B3A6B" opacity="0.8"/>
            </svg>
        </div>
    </div>
</body>
</html>
