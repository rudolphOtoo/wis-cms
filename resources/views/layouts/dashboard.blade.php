<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>@yield('title', 'Dashboard') — WIS-CMS</title>
    <link rel="icon" type="image/png" href="/favicon.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Playfair+Display:wght@400;600;700&display=swap" />
    @vite(['resources/css/app.css'])
</head>
<body class="bg-[#F8F9FC] antialiased">

    <div class="flex h-screen overflow-hidden">

        {{-- Mobile backdrop overlay --}}
        <div id="sidebar-backdrop"
             class="fixed inset-0 z-30 bg-black/50 hidden md:hidden"
             onclick="toggleSidebar()">
        </div>

        {{-- ===== SIDEBAR ===== --}}
        <aside id="sidebar"
               class="flex flex-col flex-shrink-0 z-40 fixed inset-y-0 left-0 -translate-x-full
                      md:static md:translate-x-0 md:h-full w-64
                      transition-transform duration-300 ease-in-out"
               style="background-color: #0D1F3C; height: 100dvh;">

            {{-- Sidebar header --}}
            <div class="flex items-center h-16 px-3 flex-shrink-0 gap-2"
                 style="border-bottom: 1px solid rgba(255,255,255,0.07)">
                <div class="flex items-center gap-3 min-w-0 flex-1">
                    <img src="/images/wis-logo.png" alt="WIS Logo"
                         class="w-9 h-9 object-contain flex-shrink-0" />
                    <div class="min-w-0">
                        <div class="text-white text-sm font-bold leading-tight truncate"
                             style="font-family: 'Playfair Display', serif">
                            WIS-CMS
                        </div>
                        <div class="text-[10px] leading-tight truncate" style="color: rgba(255,255,255,0.4)">
                            Methodist Church Ghana
                        </div>
                    </div>
                </div>
                <button type="button" onclick="toggleSidebar()"
                        class="md:hidden flex items-center justify-center w-11 h-11 rounded-xl hover:bg-white/10 flex-shrink-0 -mr-1"
                        style="color: rgba(255,255,255,0.75)"
                        aria-label="Close menu">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            {{-- Navigation --}}
            <nav class="flex-1 px-2 py-3 space-y-0.5 overflow-y-auto"
                 style="scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.08) transparent;">
                <x-sidebar-link href="{{ route('dashboard') }}" label="Dashboard" active="dashboard" />
                <x-sidebar-link href="#" label="Members" />
                <x-sidebar-link href="#" label="Attendance" />
                <x-sidebar-link href="#" label="Departments" />
                <x-sidebar-link href="#" label="Cells" />
                <x-sidebar-link href="#" label="Visitors" />
                <x-sidebar-link href="#" label="Messages" />
            </nav>

            {{-- User footer --}}
            <div class="flex-shrink-0 px-2 py-3"
                 style="border-top: 1px solid rgba(255,255,255,0.07)">
                <div class="flex items-center gap-3 px-2 py-1.5 rounded-xl hover:bg-white/[0.04] transition-colors">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0"
                         style="background-color: rgba(201,168,76,0.2); color: #C9A84C">
                        {{ auth()->user()?->name?[0] ?? 'U' }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-white text-xs font-semibold truncate leading-tight">
                            {{ auth()->user()?->name ?? 'User' }}
                        </div>
                        <div class="text-[10px] capitalize truncate leading-tight"
                             style="color: rgba(255,255,255,0.45)">
                            {{ auth()->user()?->roles?[0] ?? 'member' }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit"
                                class="flex items-center justify-center min-w-[44px] min-h-[44px] rounded-xl hover:bg-white/10 flex-shrink-0"
                                style="color: rgba(255,255,255,0.5)"
                                aria-label="Sign out">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- ===== MAIN CONTENT WRAPPER ===== --}}
        {{-- Use w-full on mobile, md:ml-64 on desktop to account for sidebar --}}
        <div class="flex-1 flex flex-col w-full md:ml-64 overflow-hidden">

            {{-- ===== TOP HEADER ===== --}}
            <header class="bg-white px-4 md:px-6 py-3 md:py-4 flex items-center justify-between flex-shrink-0 gap-3 w-full"
                    style="border-bottom: 1px solid #E5E9F2">

                <button type="button" onclick="toggleSidebar()"
                        class="md:hidden w-11 h-11 -ml-2 flex items-center justify-center rounded-xl hover:bg-slate-100 transition-colors flex-shrink-0"
                        style="color: #1B3A6B"
                        aria-label="Open navigation menu">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>

                <div class="flex-1 min-w-0">
                    <h1 class="text-lg md:text-xl font-semibold truncate"
                        style="font-family: 'Playfair Display', serif; color: #1B3A6B">
                        @yield('page_title', 'Dashboard')
                    </h1>
                    <p class="text-xs mt-0.5 hidden sm:block" style="color: #9ca3af">
                        @yield('page_subtitle', \Carbon\Carbon::now()->format('l, F j, Y'))
                    </p>
                </div>

                <div class="flex items-center gap-3 flex-shrink-0">
                    <div class="text-right hidden sm:block">
                        <div class="text-sm font-semibold truncate max-w-[140px]" style="color: #374151">
                            {{ auth()->user()?->name ?? '' }}
                        </div>
                        <div class="text-xs capitalize" style="color: #9ca3af">
                            {{ auth()->user()?->roles?[0] ?? '' }}
                        </div>
                    </div>
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
                         style="background-color: rgba(27,58,107,0.1)"
                         aria-label="Signed in as {{ auth()->user()?->name ?? 'user' }}"
                         role="img">
                        <span class="text-sm font-bold select-none" style="color: #1B3A6B">
                            {{ auth()->user()?->name?[0] ?? 'U' }}
                        </span>
                    </div>
                </div>
            </header>

            {{-- ===== PAGE CONTENT ===== --}}
            <main class="flex-1 overflow-y-auto p-4 md:p-6 w-full">
                @yield('content')
            </main>

        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar')
            const backdrop = document.getElementById('sidebar-backdrop')
            const isOpen = sidebar.classList.contains('translate-x-0')
            if (isOpen) {
                sidebar.classList.remove('translate-x-0')
                sidebar.classList.add('-translate-x-full')
                backdrop.classList.add('hidden')
                document.body.style.overflow = ''
            } else {
                sidebar.classList.remove('-translate-x-full')
                sidebar.classList.add('translate-x-0')
                backdrop.classList.remove('hidden')
                document.body.style.overflow = 'hidden'
            }
        }
    </script>

    @stack('scripts')
</body>
</html>
