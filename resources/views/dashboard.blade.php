<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4">
        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Provider pane (left, half width) --}}
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="mb-3 flex items-center gap-2 text-[#f47521]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    <span class="text-lg font-bold tracking-tight">Provider</span>
                </div>
                @include('providers._grid')
            </div>

            {{-- Playlist pane (right, half width) --}}
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="mb-3 flex items-center gap-2 text-[#f47521]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                    <span class="text-lg font-bold tracking-tight">Playlist</span>
                </div>
                @include('playlists._grid')
            </div>
        </div>

        {{-- Channels + Groups (provider browse): full width, below the Provider/Playlist row --}}
        @include('providers._browser')

        {{-- Playlist editor: full width, opens below when a playlist is selected --}}
        @include('playlists._editor')
    </div>
</x-layouts::app>
