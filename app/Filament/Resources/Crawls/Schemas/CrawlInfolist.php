<?php

namespace App\Filament\Resources\Crawls\Schemas;

use App\Audit\RuleCatalog;
use App\Crawler\BotAccess;
use Filament\Infolists\Components\RepeatableEntry;
use App\Filament\Resources\Crawls\CrawlColors;
use App\Models\Crawl;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CrawlInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tarama')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('project.name')->label('Proje'),
                        TextEntry::make('status')
                            ->label('Durum')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Crawl::STATUSES[$state] ?? $state)
                            ->color(fn (string $state): string => CrawlColors::status($state)),
                        TextEntry::make('health_score')
                            ->label('Sağlık skoru')
                            ->placeholder('-')
                            ->suffix(' / 100')
                            ->size('lg')
                            ->weight('bold')
                            ->color(fn (?int $state): string => CrawlColors::health($state)),
                        TextEntry::make('started_at')->label('Başlangıç')->dateTime('d.m.Y H:i')->placeholder('-'),
                        TextEntry::make('finished_at')->label('Bitiş')->dateTime('d.m.Y H:i')->placeholder('-'),
                        TextEntry::make('error')->label('Hata')->visible(fn (Crawl $record): bool => filled($record->error))->columnSpanFull(),
                    ]),
                Section::make('İlerleme')
                    ->visible(fn (Crawl $record): bool => $record->isActive())
                    ->schema([
                        ViewEntry::make('progress')->hiddenLabel()->view('crawls.progress'),
                    ]),
                Section::make('Özet')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('pages_total')->label('Taranan URL')
                            ->state(fn (Crawl $record): int => $record->pages()->where('state', 'done')->count()),
                        TextEntry::make('pages_ok')->label('200 (başarılı)')
                            ->state(fn (Crawl $record): int => $record->pages()->where('status_code', 200)->count()),
                        TextEntry::make('pages_redirect')->label('3xx (yönlendirme)')
                            ->state(fn (Crawl $record): int => $record->pages()->whereBetween('status_code', [300, 399])->count()),
                        TextEntry::make('pages_client_error')->label('4xx')
                            ->state(fn (Crawl $record): int => $record->pages()->whereBetween('status_code', [400, 499])->count()),
                        TextEntry::make('pages_server_error')->label('5xx / yanıtsız')
                            ->state(fn (Crawl $record): int => $record->pages()->where(fn ($q) => $q->where('status_code', '>=', 500)->orWhere(fn ($q) => $q->where('state', 'done')->whereNull('status_code')))->count()),
                        TextEntry::make('queued')->label('Sırada bekleyen')
                            ->state(fn (Crawl $record): int => $record->pages()->where('state', 'queued')->count()),
                        ...collect(RuleCatalog::SEVERITIES)->map(fn (string $label, string $severity) => TextEntry::make("issues_{$severity}")
                            ->label("Bulgu: {$label}")
                            ->state(fn (Crawl $record): int => $record->issues()->where('severity', $severity)->count())
                            ->color(CrawlColors::severity($severity)))->values()->all(),
                    ]),
                Section::make('Arama motorları ve AI botları erişimi')
                    ->description('robots.txt kuralı ve ana sayfanın her botun kimliğiyle bir kez istenmesi sonucu.')
                    ->collapsible()
                    ->visible(fn (Crawl $record): bool => filled($record->bot_access))
                    ->schema([
                        RepeatableEntry::make('bots')
                            ->hiddenLabel()
                            ->state(fn (Crawl $record): array => BotAccess::present($record->bot_access))
                            ->table([
                                RepeatableEntry\TableColumn::make('Bot'),
                                RepeatableEntry\TableColumn::make('Tür'),
                                RepeatableEntry\TableColumn::make('robots.txt'),
                                RepeatableEntry\TableColumn::make('Erişim testi'),
                            ])
                            ->schema([
                                TextEntry::make('name'),
                                TextEntry::make('kind'),
                                TextEntry::make('robots')->badge()->color(fn (string $state): string => $state === 'Engelli' ? 'danger' : 'success'),
                                TextEntry::make('fetch')->badge()->color(fn (string $state): string => str_starts_with($state, 'Reddedildi') ? 'warning' : (str_starts_with($state, 'Açıldı') ? 'success' : 'gray')),
                            ]),
                    ]),
            ]);
    }
}
