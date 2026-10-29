@php($p = $detail['page'])
<div class="space-y-5 text-sm">
    <dl class="grid grid-cols-2 gap-2">
        <dt class="font-medium">Durum kodu</dt><dd>{{ $p->status_code ?? 'Yanıt yok' }}</dd>
        <dt class="font-medium">Title</dt><dd>{{ $p->title ?: '—' }}</dd>
        <dt class="font-medium">Meta description</dt><dd>{{ $p->meta_description ?: '—' }}</dd>
        <dt class="font-medium">H1 ({{ $p->h1_count }})</dt><dd>{{ $p->h1 ?: '—' }}</dd>
        <dt class="font-medium">Canonical</dt><dd class="break-all">{{ $p->canonical ?: '—' }}</dd>
        <dt class="font-medium">Derinlik / Gelen link / Link puanı</dt><dd>{{ $p->depth }} / {{ $p->inlinks }} / {{ $p->link_score ?? '—' }}</dd>
        @if ($p->ai_score !== null)
            <dt class="font-medium">AI hazırlık ({{ $p->ai_score }}/100)</dt>
            <dd>@foreach (\App\Audit\AiReadiness::signals($p) as $signal => $met)<div @class(['opacity-60' => ! $met])>{{ $met ? '✓' : '✗' }} {{ \App\Audit\AiReadiness::SIGNALS[$signal][0] }}</div>@endforeach</dd>
        @endif
        @if ($p->readability !== null)
            <dt class="font-medium">Okunabilirlik (Ateşman)</dt><dd>{{ $p->readability }}/100 · {{ \App\Audit\Readability::label($p->readability) }}</dd>
        @endif
        @if (! empty($p->aeo))
            <dt class="font-medium">Alıntılanabilirlik</dt>
            <dd>Soru başlığı {{ $p->aeo['questions'] }} (kısa cevaplı {{ $p->aeo['answered'] }}) · Liste {{ $p->aeo['lists'] }} · Tablo {{ $p->aeo['tables'] }} · SSS {{ $p->aeo['faq'] ? 'var' : 'yok' }} · Dış kaynak {{ $p->aeo['sources'] }} · Rakamlı ifade {{ $p->aeo['numbers'] }}</dd>
        @endif
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

    @if ($i = $detail['inspection'])
        <section>
            <h3 class="font-semibold mb-2">Google'ın gözünden (URL denetimi, {{ \Carbon\Carbon::parse($i->inspected_at)->format('d.m.Y') }})</h3>
            @if ($i->error)
                <div class="opacity-70">Denetlenemedi: {{ $i->error }}</div>
            @else
                <dl class="grid grid-cols-2 gap-2">
                    <dt class="font-medium">Durum</dt><dd>{{ $i->coverage_state ?? '—' }}</dd>
                    <dt class="font-medium">Google'ın seçtiği canonical</dt><dd class="break-all">{{ $i->google_canonical ?? '—' }}</dd>
                    <dt class="font-medium">Son Google taraması</dt><dd>{{ $i->last_crawl_time ? \Carbon\Carbon::parse($i->last_crawl_time)->format('d.m.Y') : '—' }}</dd>
                </dl>
            @endif
        </section>
    @endif

    @if ($detail['queries']->isNotEmpty())
        <section>
            <h3 class="font-semibold mb-2">Google'da gösterildiği sorgular (son 28 gün)</h3>
            <table class="w-full">
                <tr class="text-left opacity-70"><th>Sorgu</th><th>Tıklama</th><th>Gösterim</th><th>Pozisyon</th></tr>
                @foreach ($detail['queries'] as $q)
                    <tr><td class="pe-2">{{ $q->query }}</td><td>{{ $q->clicks }}</td><td>{{ $q->impressions }}</td><td>{{ number_format((float) $q->position, 1, ',', '.') }}</td></tr>
                @endforeach
            </table>
        </section>
    @endif

    <section>
        <h3 class="font-semibold mb-2">Gelen linkler ({{ $detail['inlinks']->count() }})</h3>
        <ul class="list-disc ps-5 break-all">@forelse ($detail['inlinks'] as $u)<li>{{ $u }}</li>@empty<li class="opacity-70">Yok</li>@endforelse</ul>
    </section>

    <section>
        <h3 class="font-semibold mb-2">Giden linkler ({{ $detail['outlinks']->count() }})</h3>
        <ul class="list-disc ps-5 break-all">@forelse ($detail['outlinks'] as $u)<li>{{ $u }}</li>@empty<li class="opacity-70">Yok</li>@endforelse</ul>
    </section>
</div>
