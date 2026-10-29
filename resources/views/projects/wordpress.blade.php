<x-filament-panels::page>
    @php
        $inv = $connection?->inventory;
        $yes = fn ($v) => $v ? 'Evet' : 'Hayır';
    @endphp

    @if ($code)
        <x-filament::section heading="Bağlantı kodu" description="30 dakika geçerli ve tek kullanımlık.">
            <ol class="list-decimal ps-5 space-y-1 text-sm mb-3">
                <li><a class="text-primary-600 underline" href="{{ route('wordpress.plugin') }}">Eklentiyi indir</a> (screamingrabbit-connector.zip).</li>
                <li>Sitenin WordPress panelinde: Eklentiler → Yeni ekle → Eklenti yükle → ZIP'i seç → Şimdi kur → Etkinleştir.</li>
                <li>Ayarlar → ScreamingRabbit sayfasına aşağıdaki kodu yapıştır ve "Bağlan"a bas.</li>
            </ol>
            <textarea readonly rows="3" class="w-full font-mono text-xs rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700" onclick="this.select()">{{ $code }}</textarea>
        </x-filament::section>
    @endif

    @if (! $connection || ! $connection->isConnected())
        @unless ($code)
            <x-filament::section>
                <p class="text-sm">Bu proje henüz ScreamingRabbit Connector eklentisine bağlı değil. Eklenti, dış taramanın göremediği bilgileri
                    (tüm eklentiler ve güncelleme durumları, WordPress/PHP sürümü, hata ayıklama ve dosya düzenleyici ayarları, yönetici sayısı, taslak
                    ve zamanlanmış içerik sayıları) okur. Salt okunurdur: siteye hiçbir değişiklik yapmaz, kişisel veri toplamaz.</p>
                @if ($canManage)
                    <p class="text-sm mt-2">Başlamak için sağ üstteki <strong>Bağlantı kodu al</strong> düğmesine bas.</p>
                @endif
            </x-filament::section>
        @endunless
    @else
        <x-filament::section heading="Bağlantı">
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="font-medium">Site</dt><dd class="break-all">{{ $connection->site_url }}</dd>
                <dt class="font-medium">Eklenti sürümü</dt><dd>{{ $connection->plugin_version ?? '—' }}</dd>
                <dt class="font-medium">Bağlandı</dt><dd>{{ $connection->connected_at?->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y H:i') }}</dd>
                <dt class="font-medium">Son güncelleme</dt><dd>{{ $connection->synced_at?->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y H:i') ?? 'Henüz yok (en geç bir saat içinde)' }}</dd>
            </dl>
            @if ($connection->sync_error)
                <p class="text-sm text-danger-600 mt-2">{{ $connection->sync_error }}</p>
            @endif
        </x-filament::section>

        @if ($inv)
            <x-filament::section heading="WordPress ve sunucu">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="font-medium">WordPress</dt><dd>{{ $inv['core']['version'] ?? '—' }} @if (! empty($inv['core']['update'])) <span class="text-warning-600">→ {{ $inv['core']['update'] }} güncellemesi var</span> @endif</dd>
                    <dt class="font-medium">PHP / MySQL</dt><dd>{{ $inv['environment']['php'] ?? '—' }} / {{ $inv['environment']['mysql'] ?? '—' }}</dd>
                    <dt class="font-medium">Bellek limiti</dt><dd>{{ $inv['environment']['memory_limit'] ?? '—' }}</dd>
                    <dt class="font-medium">Arama motorlarına açık</dt><dd @class(['text-danger-600' => ! ($inv['settings']['blog_public'] ?? true)])>{{ $yes($inv['settings']['blog_public'] ?? true) }}</dd>
                    <dt class="font-medium">Kalıcı bağlantı yapısı</dt><dd>{{ ($inv['settings']['permalink'] ?? '') ?: 'Düz (?p=123)' }}</dd>
                    <dt class="font-medium">Hata ayıklama (WP_DEBUG)</dt><dd @class(['text-warning-600' => $inv['settings']['wp_debug'] ?? false])>{{ $yes($inv['settings']['wp_debug'] ?? false) }}{{ ($inv['settings']['wp_debug_display'] ?? false) ? ', hatalar ekranda görünüyor' : '' }}</dd>
                    <dt class="font-medium">Panelden dosya düzenleme kapalı</dt><dd>{{ $yes($inv['settings']['file_edit_disabled'] ?? false) }}</dd>
                    <dt class="font-medium">Çekirdek otomatik güncelleme</dt><dd>{{ ['all' => 'Tümü', 'minor' => 'Yalnızca küçük sürümler', 'off' => 'Kapalı'][$inv['settings']['auto_update_core'] ?? ''] ?? ($inv['settings']['auto_update_core'] ?? '—') }}</dd>
                    <dt class="font-medium">Herkes üye olabilir</dt><dd>{{ $yes($inv['settings']['users_can_register'] ?? false) }}</dd>
                    <dt class="font-medium">Yönetici sayısı</dt><dd>{{ $inv['settings']['admins'] ?? '—' }}</dd>
                    <dt class="font-medium">Önbellek eklentisi</dt><dd>{{ implode(', ', \App\WordPress\Connector::cachePlugins($inv)) ?: 'Yok (sunucu önbelleği olabilir)' }}</dd>
                    @if (array_key_exists('seo', $inv))
                        <dt class="font-medium">SEO eklentisi</dt><dd>{{ $inv['seo']['plugin'] ?? 'Yok' }}@if (isset($inv['seo']['sitemap'])), site haritası {{ $inv['seo']['sitemap'] ? 'açık' : 'kapalı' }}@endif</dd>
                        @if (! empty($inv['seo']['noindex_types']))
                            <dt class="font-medium">Arama dışı içerik türleri</dt><dd class="text-warning-600">{{ implode(', ', $inv['seo']['noindex_types']) }}</dd>
                        @endif
                    @endif
                </dl>
            </x-filament::section>

            @if (array_key_exists('site_health', $inv) || array_key_exists('integrity', $inv))
                @php $integrity = $inv['integrity'] ?? null; @endphp
                <x-filament::section heading="Site Sağlığı ve çekirdek dosyaları">
                    @if ($integrity)
                        @if (($integrity['modified_count'] ?? 0) + ($integrity['missing_count'] ?? 0) === 0)
                            <p class="text-sm text-success-600">Çekirdek dosyaları wordpress.org'daki {{ $integrity['version'] }} sürümüyle aynı.</p>
                        @else
                            <p class="text-sm text-danger-600">{{ $integrity['modified_count'] }} çekirdek dosyası değişmiş, {{ $integrity['missing_count'] }} dosya eksik.</p>
                            <ul class="mt-1 list-disc ps-5 text-sm">
                                @foreach (array_merge($integrity['modified'] ?? [], $integrity['missing'] ?? []) as $file)
                                    <li><code>{{ $file }}</code></li>
                                @endforeach
                            </ul>
                        @endif
                    @elseif (array_key_exists('integrity', $inv))
                        <p class="text-sm opacity-70">Çekirdek dosya kontrolü yapılamadı (site wordpress.org'a bağlanamadı).</p>
                    @endif
                    @if (array_key_exists('site_health', $inv))
                        @php $health = collect($inv['site_health'] ?? [])->sortBy(fn ($item) => $item['status'] === 'critical' ? 0 : 1); @endphp
                        @if ($health->isEmpty())
                            <p class="mt-3 text-sm text-success-600">Site Sağlığı'nda uyarı yok.</p>
                        @else
                            <ul class="mt-3 space-y-1 text-sm">
                                @foreach ($health as $item)
                                    <li><span @class(['font-medium', 'text-danger-600' => $item['status'] === 'critical', 'text-warning-600' => $item['status'] !== 'critical'])>{{ $item['status'] === 'critical' ? 'Kritik' : 'Önerilen' }}:</span> {{ $item['label'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </x-filament::section>
            @endif

            @if ($connection->vulnerabilities_checked_at)
                @php $vulnerable = $connection->vulnerabilities ?? []; @endphp
                <x-filament::section heading="Bilinen güvenlik açıkları" :description="'WPVulnerability.net veritabanı, '.$connection->vulnerabilities_checked_at->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y H:i').' kontrolü. Pasif eklentiler de dahil.'">
                    @if ($vulnerable === [])
                        <p class="text-sm text-success-600">Kurulu sürümlerde bilinen açık yok.</p>
                    @else
                        <div class="space-y-3 text-sm">
                            @foreach ($vulnerable as $component)
                                <div>
                                    <p class="font-medium text-danger-600">{{ $component['name'] }} {{ $component['version'] }}</p>
                                    <ul class="list-disc ps-5">
                                        @foreach ($component['items'] as $item)
                                            <li>
                                                @if ($item['link'])<a class="underline" href="{{ $item['link'] }}" target="_blank" rel="noopener">{{ $item['title'] }}</a>@else{{ $item['title'] }}@endif
                                                @if ($item['severity']) · {{ $item['severity'] }}@if ($item['score']) ({{ number_format($item['score'], 1) }})@endif @endif
                                                · {{ $item['fixed_in'] ? $item['fixed_in'].' sürümünde düzeldi' : ($item['unfixed'] ? 'düzeltme yok' : 'güncel sürüme geçin') }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            @endif

            @php
                $plugins = collect($inv['plugins'] ?? [])->sortBy([['active', 'desc'], ['name', 'asc']]);
                $updates = $plugins->whereNotNull('update')->count();
            @endphp
            <x-filament::section :heading="'Eklentiler ('.$plugins->count().')'" :description="$updates ? $updates.' eklentinin güncellemesi var.' : 'Tüm eklentiler güncel.'">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tr class="text-left opacity-70"><th>Eklenti</th><th>Sürüm</th><th>Durum</th><th>Güncelleme</th><th>Otomatik güncelleme</th></tr>
                        @foreach ($plugins as $p)
                            <tr>
                                <td class="pe-3">{{ $p['name'] }}</td>
                                <td>{{ $p['version'] }}</td>
                                <td>{{ $p['active'] ? 'Etkin' : 'Pasif' }}</td>
                                <td @class(['text-warning-600' => $p['update']])>{{ $p['update'] ? $p['update'].' var' : '—' }}</td>
                                <td>{{ $p['auto_update'] ? 'Açık' : '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section :heading="'Temalar ('.count($inv['themes'] ?? []).')'">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tr class="text-left opacity-70"><th>Tema</th><th>Sürüm</th><th>Durum</th><th>Güncelleme</th></tr>
                        @foreach (collect($inv['themes'] ?? [])->sortByDesc('active') as $t)
                            <tr>
                                <td class="pe-3">{{ $t['name'] }}</td>
                                <td>{{ $t['version'] }}</td>
                                <td>{{ $t['active'] ? 'Etkin' : ($t['parent'] ? 'Ana tema' : 'Pasif') }}</td>
                                <td @class(['text-warning-600' => $t['update']])>{{ $t['update'] ? $t['update'].' var' : '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section heading="İçerik">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tr class="text-left opacity-70"><th>Tür</th><th>Yayında</th><th>Taslak</th><th>Zamanlanmış</th><th>Özel</th></tr>
                        @foreach ($inv['content'] ?? [] as $type => $c)
                            <tr><td class="pe-3">{{ $type }}</td><td>{{ $c['publish'] }}</td><td>{{ $c['draft'] }}</td><td>{{ $c['future'] }}</td><td>{{ $c['private'] }}</td></tr>
                        @endforeach
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
