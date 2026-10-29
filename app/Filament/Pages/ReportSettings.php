<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Reports\ReportBranding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * The look of client reports (web, PDF and monthly reports): logo, agency name, colour, contact line, introduction.
 *
 * @property-read Schema $form
 */
class ReportSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?string $navigationLabel = 'Rapor görünümü';

    protected static ?string $title = 'Müşteri raporu görünümü';

    protected static ?string $slug = 'rapor-gorunumu';

    protected static ?int $navigationSort = 92;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isPrimary();
    }

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $branding = ReportBranding::current();
        $this->form->fill([
            'report_company' => $branding['company'],
            'report_accent' => $branding['accent'],
            'report_contact' => $branding['contact'],
            'report_intro' => $branding['intro'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Logo ve renk')
                    ->description('Raporun üst kısmında ScreamingRabbit yerine senin logon ve firma adın görünür. Alttaki "Bir '.config('screamingrabbit.brand.name').' ürünü" satırı her zaman kalır.')
                    ->columns(2)
                    ->schema([
                        Text::make(fn () => ($logo = Setting::get('report_logo'))
                            ? new HtmlString('<img src="'.e($logo).'" alt="" style="max-height:48px">')
                            : 'Henüz logo yüklenmedi; raporda ScreamingRabbit logosu kullanılır.')->columnSpanFull(),
                        FileUpload::make('logo_upload')->label('Yeni logo (PNG, JPG veya SVG, en fazla '.ReportBranding::MAX_LOGO_KB.' KB)')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])
                            ->maxSize(ReportBranding::MAX_LOGO_KB)
                            ->disk('local')->directory('report-logo-uploads')
                            ->columnSpanFull(),
                        TextInput::make('report_company')->label('Firma adı (raporun üstünde)')->maxLength(80),
                        ColorPicker::make('report_accent')->label('Vurgu rengi')->regex('/^#[0-9a-fA-F]{6}$/'),
                    ]),
                Section::make('İletişim ve giriş')
                    ->schema([
                        TextInput::make('report_contact')->label('İletişim satırı')->maxLength(160)
                            ->placeholder('0 (212) 555 00 00 · info@ajans.com · ajans.com'),
                        Textarea::make('report_intro')->label('Giriş metni (raporun başında, isteğe bağlı)')->rows(3)->maxLength(600),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Kaydet')->submit('save'),
                        Action::make('removeLogo')->label('Logoyu kaldır')->color('danger')
                            ->visible(fn (): bool => filled(Setting::get('report_logo')))
                            ->requiresConfirmation()
                            ->action(function (): void {
                                Setting::put('report_logo', null);
                                Notification::make()->title('Logo kaldırıldı')->success()->send();
                            }),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        foreach (['report_company', 'report_accent', 'report_contact', 'report_intro'] as $key) {
            Setting::put($key, $state[$key] ?? null);
        }

        $upload = $state['logo_upload'] ?? null;
        if (is_string($upload) && Storage::disk('local')->exists($upload)) {
            $mime = Storage::disk('local')->mimeType($upload);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/svg+xml', 'image/svg'], true)) {
                Setting::put('report_logo', 'data:'.($mime === 'image/svg' ? 'image/svg+xml' : $mime).';base64,'.base64_encode(Storage::disk('local')->get($upload)));
            }
            Storage::disk('local')->delete($upload);
            $this->form->fill([...$state, 'logo_upload' => null]);
        }

        Notification::make()->title('Kaydedildi')->body('Yeni raporlarda geçerli; onaylanmış aylık raporlar olduğu gibi kalır.')->success()->send();
    }
}
