@php($p = $detail['page'])
<div class="space-y-5 text-sm">
    <dl class="grid grid-cols-2 gap-2">
        <dt class="font-medium">Durum kodu</dt><dd>{{ $p->status_code ?? 'Yanıt yok' }}</dd>
        <dt class="font-medium">Title</dt><dd>{{ $p->title ?: '—' }}</dd>
        <dt class="font-medium">Meta description</dt><dd>{{ $p->meta_description ?: '—' }}</dd>
        <dt class="font-medium">H1 ({{ $p->h1_count }})</dt><dd>{{ $p->h1 ?: '—' }}</dd>
        <dt class="font-medium">Canonical</dt><dd class="break-all">{{ $p->canonical ?: '—' }}</dd>
        <dt class="font-medium">Kelime / Yanıt</dt><dd>{{ $p->word_count ?? '—' }} / {{ $p->response_ms ? $p->response_ms.' ms' : '—' }}</dd>
    </dl>

    <section>
        <h3 class="font-semibold mb-2">Sorunlar ({{ count($detail['findings']) }})</h3>
        @forelse ($detail['findings'] as $f)
            <div class="mb-3">
                <div class="font-medium">{{ $f['severity'] }}: {{ $f['title'] }}</div>
                @if ($f['detail']) <div class="opacity-70">{{ $f['detail'] }}</div> @endif
                @if ($f['fix']) <div class="opacity-80">Öneri: {{ $f['fix'] }}</div> @endif
            </div>
        @empty
            <div class="opacity-70">Bu sayfada sorun bulunmadı.</div>
        @endforelse
    </section>

    <section>
        <h3 class="font-semibold mb-2">Gelen linkler ({{ $detail['inlinks']->count() }})</h3>
        <ul class="list-disc ps-5 break-all">@forelse ($detail['inlinks'] as $u)<li>{{ $u }}</li>@empty<li class="opacity-70">Yok</li>@endforelse</ul>
    </section>

    <section>
        <h3 class="font-semibold mb-2">Giden linkler ({{ $detail['outlinks']->count() }})</h3>
        <ul class="list-disc ps-5 break-all">@forelse ($detail['outlinks'] as $u)<li>{{ $u }}</li>@empty<li class="opacity-70">Yok</li>@endforelse</ul>
    </section>
</div>
