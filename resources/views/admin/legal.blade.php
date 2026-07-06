@extends('admin.layout')
@section('title', 'Legal')
@section('content')
<style>
    .legal-doc { max-width:48rem; }
    .legal-doc textarea { width:100%; min-height:20rem; background:#0e0f13; color:var(--text);
        border:1px solid rgba(255,255,255,.14); border-radius:.55rem; padding:.7rem .8rem;
        font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.82rem; line-height:1.55; resize:vertical; }
    .legal-head { display:flex; align-items:center; gap:.6rem; margin-top:2rem; }
    .legal-head h2 { margin:0; }
    .badge { font-size:.7rem; padding:.1rem .5rem; border-radius:1rem; border:1px solid rgba(255,255,255,.2); color:#9aa0aa; }
    .badge.custom { color:var(--accent); border-color:rgba(244,117,33,.5); }
    .legal-actions { display:flex; align-items:center; gap:.7rem; margin-top:.8rem; }
    .view-link { color:var(--accent); text-decoration:none; font-size:.85rem; margin-left:auto; }
    .view-link:hover { text-decoration:underline; }
</style>

<h1>Legal pages</h1>
<p class="muted">Edit the <strong>Privacy Policy</strong>, <strong>Terms of Service</strong>, and <strong>Cookie Policy</strong> shown to visitors.
Written in Markdown. Each ships with a starter template — customise it for your service and jurisdiction, or
<strong>Reset to default</strong> to restore the shipped text. These are templates, not legal advice.</p>

@foreach ($docs as $slug => $d)
    <div class="legal-doc">
        <div class="legal-head">
            <h2>{{ $d['title'] }}</h2>
            <span class="badge {{ $d['custom'] ? 'custom' : '' }}">{{ $d['custom'] ? 'customised' : 'default' }}</span>
            <a class="view-link" href="{{ route('legal.'.$slug) }}" target="_blank" rel="noopener">View page &#8599;</a>
        </div>

        <form method="POST" action="{{ route('admin.legal.update') }}">
            @csrf @method('PUT')
            <textarea name="{{ $slug }}" spellcheck="true">{{ $d['markdown'] }}</textarea>
            <div class="legal-actions">
                <button type="submit">Save {{ $d['title'] }}</button>
            </div>
        </form>

        @if ($d['custom'])
            <form method="POST" action="{{ route('admin.legal.reset', $slug) }}"
                  onsubmit="return confirm('Reset the {{ $d['title'] }} to the shipped default? Your edits will be lost.')"
                  style="margin-top:.6rem">
                @csrf @method('DELETE')
                <button type="submit" class="ghost">Reset {{ $d['title'] }} to default</button>
            </form>
        @endif
    </div>
@endforeach
@endsection
