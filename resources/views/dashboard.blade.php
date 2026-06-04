<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        {{-- Provider pane: shows the table (with headers) even when empty; + opens an overlay --}}
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="mb-3 flex items-center gap-2 text-[#f47521]">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                <span class="text-lg font-bold tracking-tight">Provider</span>
            </div>
            @include('providers._grid')
        </div>

        <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
        </div>
    </div>
</x-layouts::app>
