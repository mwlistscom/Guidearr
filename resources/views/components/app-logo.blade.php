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
    // 56px, and the mark FILLS it. The previous 48px frame kept 2px of padding and a 1px
    // border, so with object-fit:contain the mark itself only rendered at 42px — the box
    // grew, the logo barely did. The tile is decoration; the mark is the point, so the
    // padding and border are gone and the background only shows through a transparent icon.
    $frame = 'width:56px;height:56px;border-radius:10px;background:#0e0f13;'.
        'flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden';

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
