@props([
    'sidebar' => false,
    'href' => '/',
])

@php
    $brandName = config('app.name', 'Guidearr');

    // The brand mark must look identical here and in the admin sidebar, which is a
    // separate layout styled with plain CSS (.sidebar .brand .logo). Both carry these
    // exact values — change them together or the two chromes drift apart again.
    //
    // Explicit CSS rather than a Tailwind size utility on purpose: public/build is
    // gitignored and the documented upgrade path never runs `npm run build`, so an
    // upgraded install keeps its old stylesheet. A utility it has never compiled would
    // silently do nothing and leave the image unconstrained. Inline styles always apply.
    $frame = 'width:48px;height:48px;border-radius:9px;background:#0e0f13;'.
        'border:1px solid rgba(255,255,255,.10);padding:2px;flex-shrink:0;'.
        'display:flex;align-items:center;justify-content:center;overflow:hidden';

    $mark = 'width:100%;height:100%;object-fit:contain';
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo" style="{{ $frame }}">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" style="{{ $mark }}" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo" style="{{ $frame }}">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" style="{{ $mark }}" />
        </x-slot>
    </flux:brand>
@endif
