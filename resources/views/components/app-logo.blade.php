@props([
    'sidebar' => false,
    'href' => '/',
])

@php
    $brandName = config('app.name', 'Guidearr');

    // Just the icon: no tile, no border, no padding, no frame. The admin sidebar carries
    // the same values in plain CSS (.sidebar .brand .logo) — change both together.
    //
    // Explicit CSS rather than a Tailwind size utility: public/build is gitignored and the
    // documented upgrade path never runs `npm run build`, so an upgraded install keeps its
    // old stylesheet and a class it never compiled would silently do nothing.
    $mark = 'width:56px;height:56px;object-fit:contain;flex-shrink:0';
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" style="{{ $mark }}" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" style="{{ $mark }}" />
        </x-slot>
    </flux:brand>
@endif
