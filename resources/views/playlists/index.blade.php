<x-layouts::app :title="__('Playlists')">
    <div style="padding:.25rem">
        <h1 style="font-size:1.4rem;font-weight:800;letter-spacing:-.02em;margin-bottom:1rem">Playlists</h1>
        @include('playlists._grid')
        @include('playlists._editor')
    </div>
</x-layouts::app>
