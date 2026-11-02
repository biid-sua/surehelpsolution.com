{{-- Shown while someone previews a website design through its signed link (D121). --}}
@php($previewing = app(\App\Support\Ui\SiteDesigns::class)->previewing())
@if ($previewing)
    <form method="POST" action="{{ route('home.preview.end') }}" role="status"
          style="margin:0;display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;justify-content:center;background:#6d5efc;color:#fff;font:500 14px/1.4 system-ui,sans-serif;padding:8px 16px;text-align:center;position:relative;z-index:60">
        @csrf
        <span>You're previewing the {{ app(\App\Support\Ui\SiteDesigns::class)->all()[$previewing]['label'] }} design. Visitors see the design chosen in Admin › Appearance.</span>
        <button type="submit" style="background:#fff;color:#1b1336;border:0;border-radius:999px;padding:4px 12px;font:600 13px/1.4 system-ui,sans-serif;cursor:pointer">Leave preview</button>
    </form>
@endif
