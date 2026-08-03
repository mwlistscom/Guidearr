@props([
    'sidebar' => false,
    'href' => '/',
])

@php
    $brandName = config('app.name', 'Guidearr');

    // Just the icon: no tile, no border, no padding. The admin sidebar carries the same
    // values in plain CSS (.sidebar .brand .logo) — change both together.
    //
    // Flux wraps this slot in a div classed `[:where(&)]:h-6 ... overflow-hidden`, i.e.
    // 24px with clipping, and puts it inside an `h-10` anchor. A 63px mark was therefore
    // cut off top and bottom on the dashboard while the admin sidebar — which has no fixed
    // row height — showed it whole. These inline styles override both: inline beats a
    // class, and `[:where()]` carries no specificity at all.
    //
    // Explicit CSS rather than Tailwind utilities for the usual reason too: public/build
    // is gitignored, so an install that upgrades without rebuilding assets would silently
    // ignore a class it has never compiled.
    $row = 'height:auto;min-height:0';
    $frame = 'width:63px;height:63px;overflow:visible;flex-shrink:0;'.
        'display:flex;align-items:center;justify-content:center';
    $mark = 'width:63px;height:63px;object-fit:contain;flex-shrink:0';
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="$brandName" :href="$href" style="{{ $row }}" {{ $attributes }}>
        <x-slot name="logo" style="{{ $frame }}">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" style="{{ $mark }}" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" :href="$href" style="{{ $row }}" {{ $attributes }}>
        <x-slot name="logo" style="{{ $frame }}">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" style="{{ $mark }}" />
        </x-slot>
    </flux:brand>
@endif
