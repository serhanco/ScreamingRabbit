{{--
    Plain-language, step-by-step guide for creating the Google sign-in key (OAuth client).
    Used by the dashboard widget ($withForm = true: the key fields and connect button sit inside step 6/7)
    and by the Google settings page ($withForm = false: the fields are in the section below the guide).
    Google Cloud button names are given in both Turkish and English because the console follows the account language.
    Only the primary admin sets up the shared key; every other admin just connects their own Google account (last step).
--}}
@php
    $withForm ??= false;
    $connectOnly = ! \App\Google\GoogleClient::canManageClient(auth()->user());
    $redirect = \App\Google\GoogleClient::redirectUri();
    $configured = \App\Google\GoogleClient::configured();
    $steps = [
        [
            'title' => 'Google Cloud\'da bir proje aç',
            'body' => '"Aç" ile açılan sayfada <b>Proje adı</b> kutusuna <b>ScreamingRabbit</b> yaz ve mavi <b>Oluştur / Create</b> düğmesine bas. Birkaç saniye sürer.',
            'url' => 'https://console.cloud.google.com/projectcreate',
            'copy' => 'ScreamingRabbit',
        ],
        [
            'title' => 'Search Console erişimini aç',
            'body' => 'Açılan sayfanın en üstünde <b>ScreamingRabbit</b> yazdığından emin ol, sonra mavi <b>Etkinleştir / Enable</b> düğmesine bas.',
            'url' => 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com',
        ],
        [
            'title' => 'Uygulamanı Google\'a tanıt',
            'body' => '<b>Başlayın / Get started</b> düğmesine bas. Sırayla: uygulama adına <b>ScreamingRabbit</b>, e-posta alanına kendi adresini yaz, <b>İleri / Next</b>. Kitle olarak <b>Harici / External</b> seç, <b>İleri</b>. Yine kendi e-postanı yaz, <b>İleri</b>. Onay kutusunu işaretle, <b>Oluştur / Create</b>.',
            'url' => 'https://console.cloud.google.com/auth/overview',
        ],
        [
            'title' => 'Uygulamayı yayına al',
            'body' => '<b>Uygulamayı yayınla / Publish app</b> düğmesine bas, çıkan pencerede <b>Onayla / Confirm</b>. Bu adımı atlama: atlanırsa bağlantı her 7 günde bir kopar.',
            'url' => 'https://console.cloud.google.com/auth/audience',
        ],
        [
            'title' => 'Giriş anahtarını oluştur',
            'body' => '<b>İstemci oluştur / Create client</b> düğmesine bas. Uygulama türü olarak <b>Web uygulaması / Web application</b> seç. Aşağıdaki <b>Yetkili yönlendirme URI\'leri / Authorized redirect URIs</b> başlığında <b>URI ekle / Add URI</b> düğmesine bas ve şu adresi yapıştır (Kopyala düğmesiyle). Sonra en alttaki <b>Oluştur / Create</b> düğmesine bas.',
            'url' => 'https://console.cloud.google.com/auth/clients',
            'copy' => $redirect,
        ],
        [
            'title' => 'İki değeri buraya yapıştır',
            'body' => $withForm
                ? 'Google bir pencerede iki değer gösterir: <b>İstemci kimliği / Client ID</b> ve <b>İstemci gizli anahtarı / Client secret</b>. İkisini de kopyalayıp aşağıdaki kutulara yapıştır ve <b>Kaydet</b>\'e bas. Gizli anahtar bir daha gösterilmez, pencereyi kapatmadan kopyala.'
                : 'Google bir pencerede iki değer gösterir: <b>İstemci kimliği / Client ID</b> ve <b>İstemci gizli anahtarı / Client secret</b>. İkisini bu sayfadaki "OAuth istemcisi" kutularına yapıştır ve <b>Kaydet</b>\'e bas. Gizli anahtar bir daha gösterilmez, pencereyi kapatmadan kopyala.',
            'form' => $withForm,
            'done' => $configured,
        ],
        [
            'title' => 'Google hesabını bağla',
            'body' => '<b>Google hesabını bağla</b> düğmesine bas ve müşteri sitelerini Search Console\'da gören hesabı seç. "Google bu uygulamayı doğrulamadı" yazısı çıkarsa korkma: uygulama senin. <b>Gelişmiş</b>\'e, sonra <b>ScreamingRabbit\'e git</b>\'e bas ve izin ver. Bitince her projenin ayarlarından ilgili Search Console mülkünü seç.',
            'connect' => true,
        ],
    ];
    if ($connectOnly) {
        $steps = [array_merge(end($steps), ['body' => '<b>Google hesabını bağla</b> düğmesine bas ve kendi müşteri sitelerini Search Console\'da gören hesabını seç. "Google bu uygulamayı doğrulamadı" yazısı çıkarsa <b>Gelişmiş</b>\'e, sonra <b>ScreamingRabbit\'e git</b>\'e bas ve izin ver. Bitince kendi projelerinin ayarlarından ilgili Search Console mülkünü seç. Site listeni yalnızca sen görürsün.'])];
    }
    $total = count($steps);
@endphp

<div style="display: grid; gap: .75rem;">
    @if ($connectOnly)
        <p style="margin: 0; line-height: 1.6;">
            Projelerinin Google'daki tıklama, gösterim ve arama sorgusu verilerini görmek için kendi Google hesabını bir kez bağlaman yeterli.
        </p>
    @else
    <p style="margin: 0; line-height: 1.6;">
        ScreamingRabbit'in müşteri sitelerinin Google'daki tıklama, gösterim ve arama sorgusu verilerini okuyabilmesi için Google'dan bir kerelik izin alması gerekiyor.
        Bunun için Google'da sana özel bir giriş anahtarı oluşturacaksın. Yaklaşık 10 dakika sürer; her adımda "Aç" düğmesi doğru sayfayı yeni sekmede açar.
    </p>
    @endif

    @foreach ($steps as $i => $step)
        <div style="display: flex; gap: .875rem; padding: .875rem 1rem; border: 1px solid rgba(127, 127, 127, .25); border-radius: .75rem;">
            <div style="flex: none; width: 2rem; height: 2rem; border-radius: 9999px; display: grid; place-items: center; font-weight: 700; background: {{ ($step['done'] ?? false) ? 'rgb(22 163 74)' : 'var(--primary-600)' }}; color: #fff;">
                {{ ($step['done'] ?? false) ? '✓' : $i + 1 }}
            </div>
            <div style="flex: 1; min-width: 0; display: grid; gap: .5rem;">
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; justify-content: space-between;">
                    <div>
                        <span style="font-size: .75rem; opacity: .65;">Adım {{ $i + 1 }}/{{ $total }}</span>
                        <div style="font-weight: 600;">{{ $step['title'] }}</div>
                    </div>
                    @isset($step['url'])
                        <x-filament::button tag="a" :href="$step['url']" target="_blank" rel="noopener" size="sm" icon="heroicon-m-arrow-top-right-on-square" icon-position="after">
                            Aç
                        </x-filament::button>
                    @endisset
                </div>
                <div style="line-height: 1.6; opacity: .9;">{!! $step['body'] !!}</div>

                @isset($step['copy'])
                    <div x-data="{ copied: false }" style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;">
                        <code style="padding: .375rem .625rem; border-radius: .5rem; background: rgba(127, 127, 127, .15); font-weight: 600; word-break: break-all;">{{ $step['copy'] }}</code>
                        <x-filament::button color="gray" size="sm" icon="heroicon-m-clipboard-document"
                            x-on:click="navigator.clipboard.writeText(@js($step['copy'])); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</span>
                        </x-filament::button>
                    </div>
                @endisset

                @if ($step['form'] ?? false)
                    <form wire:submit="saveKeys" style="display: grid; gap: .5rem; max-width: 40rem;">
                        <label style="display: grid; gap: .25rem;">
                            <span style="font-size: .875rem; font-weight: 500;">İstemci kimliği (Client ID)</span>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="clientId" placeholder="…apps.googleusercontent.com" />
                            </x-filament::input.wrapper>
                        </label>
                        <label style="display: grid; gap: .25rem;">
                            <span style="font-size: .875rem; font-weight: 500;">İstemci gizli anahtarı (Client secret)</span>
                            <x-filament::input.wrapper>
                                <x-filament::input type="password" wire:model="clientSecret" placeholder="GOCSPX-…" />
                            </x-filament::input.wrapper>
                        </label>
                        <div><x-filament::button type="submit" size="sm">Kaydet</x-filament::button></div>
                    </form>
                @endif

                @if ($withForm && ($step['connect'] ?? false))
                    <div>
                        @if ($configured)
                            <x-filament::button tag="a" :href="route('filament.admin.google.connect')" icon="heroicon-m-link">Google hesabını bağla</x-filament::button>
                        @else
                            <span style="font-size: .875rem; opacity: .65;">Bu düğme 6. adımda iki değeri kaydedince açılır.</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
