<x-layouts::app :title="__('Providers')">
    <div style="padding:.25rem">
        <h1 style="font-size:1.4rem;font-weight:800;letter-spacing:-.02em;margin-bottom:1rem">Providers</h1>
        @include('providers._grid')
        @include('providers._browser')
    </div>
</x-layouts::app>
