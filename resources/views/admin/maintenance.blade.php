@extends('admin.layout')
@section('title', 'Maintenance')
@section('content')
@php
    $human = function ($b) {
        $b = (float) $b;
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
        return ($i === 0 ? (int) $b : number_format($b, 1)) . ' ' . $u[$i];
    };
    $ago = function ($dt) {
        return $dt ? $dt->diffForHumans() : 'never served';
    };
@endphp
<h1>Maintenance</h1>

@if (session('status'))
    <div class="flash">{{ session('status') }}</div>
@endif

<p class="muted">A playlist is counted as <strong>stale</strong> when it hasn't been served (M3U, EPG, or STRM) within the chosen window — or has never been served. Deleting a playlist also removes its SQLite store on disk. Providers are shown for reference only.</p>

<form method="GET" action="{{ route('admin.maintenance') }}" class="daysbar">
    <label>Not served in the last
        <input type="number" name="days" min="0" max="3650" value="{{ $days }}"> days
    </label>
    <button type="submit">Show</button>
    <span class="muted">{{ $totalStale }} stale · {{ $human($reclaimBytes) }} reclaimable</span>
</form>

<h2>Stale playlists</h2>
@if ($stale->isEmpty())
    <p class="muted">No playlists match — nothing to prune in this window.</p>
@else
<form method="POST" action="{{ route('admin.maintenance.prune') }}"
      onsubmit="return confirm('Permanently delete the selected playlist(s) and their stores? This cannot be undone.')">
    @csrf
    <table class="tbl">
        <thead>
            <tr>
                <th class="ck"><input type="checkbox" id="all" checked></th>
                <th>Playlist</th><th>Owner</th><th>Last served</th><th class="r">Store size</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($stale as $p)
                <tr>
                    <td class="ck"><input type="checkbox" name="ids[]" value="{{ $p['id'] }}" class="row" checked></td>
                    <td>{{ $p['name'] }}</td>
                    <td class="muted">{{ $p['user'] }}</td>
                    <td class="muted">{{ $ago($p['last']) }}@if($p['last']) <span class="dim">({{ $p['last']->format('Y-m-d') }})</span>@endif</td>
                    <td class="r">{{ $human($p['bytes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <button type="submit" class="danger">Delete selected</button>
</form>
@endif

<h2 style="margin-top:2.2rem">Provider activity</h2>
<p class="muted">Last time each provider backed a served playlist. Use this to spot providers that have gone cold.</p>
<table class="tbl">
    <thead><tr><th>Provider</th><th>Type</th><th>Playlists</th><th>Last used</th><th class="r">Store size</th></tr></thead>
    <tbody>
        @forelse ($providers as $p)
            <tr>
                <td>{{ $p['name'] }}</td>
                <td class="muted">{{ $p['type'] }}</td>
                <td class="muted">{{ $p['playlists'] }}</td>
                <td class="muted">{{ $ago($p['last']) }}@if($p['last']) <span class="dim">({{ $p['last']->format('Y-m-d') }})</span>@endif</td>
                <td class="r">{{ $human($p['bytes']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No providers.</td></tr>
        @endforelse
    </tbody>
</table>

<style>
    .muted { color:var(--muted); }
    .muted .dim { opacity:.6; }
    .flash { background:rgba(34,197,94,.15); border:1px solid rgba(34,197,94,.4); color:#bbf7d0;
        padding:.6rem .9rem; border-radius:.5rem; margin-bottom:1rem; }
    .daysbar { display:flex; gap:.8rem; align-items:center; flex-wrap:wrap; margin:1rem 0 .4rem; }
    .daysbar input { width:5rem; background:#0e0f13; border:1px solid rgba(255,255,255,.18);
        color:#e6e7ea; border-radius:.4rem; padding:.3rem .5rem; }
    .tbl { width:100%; border-collapse:collapse; margin:.6rem 0 1rem; font-size:.85rem; }
    .tbl th, .tbl td { text-align:left; padding:.5rem .6rem; border-bottom:1px solid var(--border); }
    .tbl th { color:var(--muted); font-weight:600; }
    .tbl .r { text-align:right; }
    .tbl .ck { width:2.2rem; text-align:center; }
    button.danger { background:#dc2626; color:#fff; border:none; border-radius:.45rem; padding:.5rem .9rem; cursor:pointer; }
    button.danger:hover { filter:brightness(1.1); }
</style>

<script>
    document.getElementById('all')?.addEventListener('change', function () {
        document.querySelectorAll('.row').forEach(c => { c.checked = this.checked; });
    });
</script>
@endsection
