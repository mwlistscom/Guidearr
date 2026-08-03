@props([
    'sidebar' => false,
    'href' => '/',
])

@php($brandName = config('app.name', 'Guidearr'))

{{--
    The mark in the top-left of the app chrome, enlarged from size-8 (32px) to size-10
    (40px) — 32px left anything with detail in it barely legible.

    Only utilities ALREADY in the compiled CSS may be used here. public/build is
    gitignored and the documented upgrade path (git pull; docker compose up -d --build)
    does not run `npm run build`, so an upgraded install keeps its old stylesheet. A class
    it has never compiled would silently have no effect — leaving the image unconstrained
    and blowing out the sidebar. size-10 is present; size-11/12 are not.

    object-contain, so a non-square icon is letterboxed rather than distorted.
--}}

@if($sidebar)
    <flux:sidebar.brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center overflow-hidden rounded-md">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" class="size-10 object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center overflow-hidden rounded-md">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" class="size-10 object-contain" />
        </x-slot>
    </flux:brand>
@endif
