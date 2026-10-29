<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Concerns\ScopedToUserProjects;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Report;
use App\Monitoring\Channels;
use App\Reports\CrawlReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Monthly client reports (docs/09 Faz 4): prepared automatically at the start of each month, approved here, then
 * sent to the client, who can also open every earlier report from the project's archive link.
 */
class ReportResource extends Resource
{
    use ScopedToUserProjects;

    protected static string $permission = 'report';

    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $modelLabel = 'Müşteri raporu';

    protected static ?string $pluralModelLabel = 'Müşteri raporları';

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $drafts = static::getEloquentQuery()->where('status', 'draft')->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('project.client'))
            ->columns([
                TextColumn::make('project.name')->label('Proje')->searchable()
                    ->description(fn (Report $record): ?string => $record->project->client?->name),
                TextColumn::make('period')->label('Dönem')->sortable()
                    ->formatStateUsing(fn (Report $record): string => $record->periodLabel()),
                TextColumn::make('health_score')->label('Skor')->suffix(' / 100')->placeholder('-'),
                TextColumn::make('status')->label('Durum')->badge()
                    ->formatStateUsing(fn (string $state): string => Report::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => ['draft' => 'warning', 'approved' => 'info', 'sent' => 'success'][$state] ?? 'gray'),
                TextColumn::make('sent_at')->label('Gönderildi')->dateTime('d.m.Y H:i')->placeholder('—')
                    ->description(fn (Report $record): ?string => $record->sent_to),
            ])
            ->defaultSort('period', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(Report::STATUSES),
                SelectFilter::make('project')->label('Proje')->relationship('project', 'name'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Önizle')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Report $record): string => route('filament.admin.reports.preview', $record), shouldOpenInNewTab: true),
                Action::make('approve')
                    ->label('Onayla')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Report $record): bool => $record->status === 'draft' && static::canEdit($record))
                    ->requiresConfirmation()
                    ->modalDescription('Rapor müşteriye açılabilir hale gelir ve projenin rapor arşivinde görünür. Henüz kimseye gönderilmez.')
                    ->action(function (Report $record): void {
                        $record->approve(auth()->user());
                        Notification::make()->title('Rapor onaylandı')->body($record->url())->success()->send();
                    }),
                Action::make('send')
                    ->label('Müşteriye gönder')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (Report $record): bool => $record->status !== 'draft' && static::canEdit($record))
                    ->fillForm(fn (Report $record): array => ['to' => $record->sent_to ?? $record->project->client?->contact_email])
                    ->schema([TextInput::make('to')->label('Alıcı e-posta')->email()->required()])
                    ->modalDescription('Rapor bağlantısı ve geçmiş raporların arşivi tek bir e-postayla gider.')
                    ->action(function (Report $record, array $data): void {
                        $project = $record->project;
                        $body = "Merhaba,\n\n{$project->name} için {$record->periodLabel()} SEO raporu hazır:\n".$record->url()
                            ."\n\nGeçmiş raporların tamamı: ".route('report.archive', $project->archive_token)
                            ."\n\nİyi çalışmalar,\n".config('screamingrabbit.brand.name');
                        $sent = Channels::email($data['to'], "{$project->name} · {$record->periodLabel()} SEO raporu", $body);
                        if ($sent !== true) {
                            Notification::make()->title('E-posta gönderilemedi')
                                ->body($sent === null ? 'Bildirim ayarlarında gönderen e-posta hesabı kurulu değil.' : 'Gönderen hesabın bilgilerini kontrol et.')->danger()->send();

                            return;
                        }
                        $record->update(['status' => 'sent', 'sent_at' => now(), 'sent_to' => $data['to']]);
                        Notification::make()->title('Rapor gönderildi')->success()->send();
                    }),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (Report $record) => response()->streamDownload(fn () => print (CrawlReport::renderPdf($record->pdf_html)), $record->filename(), ['Content-Type' => 'application/pdf'])),
                Action::make('archive')
                    ->label('Arşiv bağlantısı')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->visible(fn (Report $record): bool => filled($record->project->archive_token))
                    ->modalHeading('Müşterinin rapor arşivi')
                    ->modalDescription(fn (Report $record): string => 'Bu bağlantıyla müşteri onaylanmış tüm raporları görür: '.route('report.archive', $record->project->archive_token))
                    ->modalSubmitAction(false),
                DeleteAction::make()->visible(fn (Report $record): bool => $record->status === 'draft' && static::canEdit($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
