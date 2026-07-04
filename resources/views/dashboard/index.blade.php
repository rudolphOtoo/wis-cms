@extends('layouts.dashboard')

@section('title', 'Dashboard')
@section('page_title', 'Dashboard')

@section('content')
    {{-- Hero greeting --}}
    @php
        $hour = now()->hour;
        if ($hour < 12)      $greeting = 'Good morning';
        elseif ($hour < 17)  $greeting = 'Good afternoon';
        else                 $greeting = 'Good evening';
        $name = auth()->user()?->name ?? 'there';
        $firstName = explode(' ', $name)[0];
    @endphp

    <div class="space-y-6" style="max-width: 1440px">

        {{-- Welcome banner --}}
        <section class="rounded-xl relative overflow-hidden p-6 md:p-10"
                 style="background: linear-gradient(135deg,#002452 0%,#1b3a6b 100%)">
            <div class="relative z-10">
                <h2 class="font-bold text-white text-2xl md:text-4xl leading-tight break-words"
                    style="font-family: 'Playfair Display', serif">
                    {{ $greeting }}, {{ $firstName }}
                </h2>
                <p class="mt-1 text-sm md:text-base" style="color: rgba(255,255,255,0.8)">
                    Here is what's happening at Wesleyan International today.
                </p>
            </div>
            <div class="absolute rounded-full pointer-events-none"
                 style="right: -10%; top: -50%; width: 384px; height: 384px; background: rgba(255,255,255,0.05); filter: blur(60px)">
            </div>
        </section>

        {{-- Stat cards: 1 col mobile → 2 col tablet → 4 col desktop --}}
        <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="surface-card p-6 flex flex-col justify-between break-words" style="min-height: 140px">
                <div class="flex justify-between items-start gap-2">
                    <p style="font-size: 14px; font-weight: 600; color: #44474f">Active Members</p>
                    <span class="flex-shrink-0" style="color: #1B3A6B">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </span>
                </div>
                <div class="mt-6">
                    <span style="font-family: 'Playfair Display', serif; font-size: 48px; font-weight: 700; line-height: 1; color: #1B3A6B">
                        1,284
                    </span>
                </div>
            </div>

            <div class="surface-card p-6 flex flex-col justify-between break-words" style="min-height: 140px">
                <div class="flex justify-between items-start gap-2">
                    <p style="font-size: 14px; font-weight: 600; color: #44474f">Last Sunday Attendance</p>
                    <span class="flex-shrink-0" style="color: #1B3A6B">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                </div>
                <div class="mt-6">
                    <span style="font-family: 'Playfair Display', serif; font-size: 48px; font-weight: 700; line-height: 1; color: #1B3A6B">
                        847
                    </span>
                </div>
            </div>

            <div class="surface-card p-6 flex flex-col justify-between break-words" style="min-height: 140px">
                <div class="flex justify-between items-start gap-2">
                    <p style="font-size: 14px; font-weight: 600; color: #44474f">This Month Income</p>
                    <span class="flex-shrink-0" style="color: #1B3A6B">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </span>
                </div>
                <div class="mt-6">
                    <div class="flex items-baseline gap-1 flex-wrap">
                        <span style="font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 700; color: #1B3A6B">GHS</span>
                        <span style="font-family: 'Playfair Display', serif; font-size: 40px; font-weight: 700; line-height: 1; color: #1B3A6B">
                            45,280
                        </span>
                    </div>
                    <div class="flex items-center gap-1 mt-1" style="font-size: 12px; font-weight: 700; color: #15803d">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        <span>12% from last month</span>
                    </div>
                </div>
            </div>

            <div class="surface-card p-6 flex flex-col justify-between break-words" style="min-height: 140px">
                <div class="flex justify-between items-start gap-2">
                    <p style="font-size: 14px; font-weight: 600; color: #44474f">New Visitors This Month</p>
                    <span class="flex-shrink-0" style="color: #1B3A6B">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                    </span>
                </div>
                <div class="mt-6">
                    <span style="font-family: 'Playfair Display', serif; font-size: 48px; font-weight: 700; line-height: 1; color: #1B3A6B">
                        63
                    </span>
                </div>
            </div>
        </section>

    </div>
@endsection
