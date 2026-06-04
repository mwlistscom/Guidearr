@props([
    'sidebar' => false,
    'href' => '/',
])

@php($brandName = config('app.name', 'Guidearr'))

@if($sidebar)
    <flux:sidebar.brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" class="size-8 object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" :href="$href" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md">
            <img src="{{ route('branding.icon') }}" alt="{{ $brandName }}" class="size-8 object-contain" />
        </x-slot>
    </flux:brand>
@endif
