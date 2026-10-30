<?php

namespace App\Filament\Resources\Crawls\Schemas;

use App\Audit\HealthScore;
use App\Crawler\BotAccess;
use App\Crawler\CrawlComparison;
use App\Crawler\CustomExtraction;
use App\Models\Crawl;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CrawlInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->schema([ViewEntry::make('overview')->hiddenLabel()->view('crawls.overview')])
                    ->columnSpanFull(),
                Section::make('Sağlık skoru nasıl hesaplandı?')
                    ->description(HealthScore::EXPLANATION)
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Crawl $record): bool => $record->health_score !== null)
                    ->schema([
                        ViewEntry::make('score_breakdown')->hiddenLabel()->view('crawls.score-breakdown'),
                    ]),
                Section::make('Önceki taramaya göre değişenler')
                    ->description('Aynı projenin bir önceki tamamlanmış taramasıyla karşılaştırma.')
                    ->collapsible()
                    ->visible(fn (Crawl $record): bool => $record->status === 'completed' && (new CrawlComparison($record))->previous() !== null)
                    ->schema([
                        ViewEntry::make('comparison')->hiddenLabel()->view('crawls.comparison'),
                    ]),
                Section::make('Sayfa hızı (mobil)')
                    ->description('Google PageSpeed Insights ile örnek sayfalar: ana sayfa ve her içerik türünün en güçlü sayfası. Ölçümler tarama bittikten sonra birkaç dakikada tamamlanır.')
                    ->collapsible()
                    ->visible(fn (Crawl $record): bool => filled($record->pagespeed))
                    ->schema([
                        ViewEntry::make('pagespeed')->hiddenLabel()->view('crawls.pagespeed'),
                    ]),
                Section::make('Özel arama sonuçları')
                    ->description('Proje ayarlarındaki özel aramaların bu taramadaki sonuçları. Sayfa bazında değerler CSV\'de.')
                    ->collapsible()
                    ->visible(fn (Crawl $record): bool => $record->status === 'completed' && CustomExtraction::valid($record->settings['custom'] ?? []) !== [])
                    ->schema([
                        ViewEntry::make('custom')->hiddenLabel()->view('crawls.custom'),
                    ]),
                Section::make('Dizin dağılımı')
                    ->description('Sayfaların URL dizinlerine göre sayısı, derinliği ve hataları.')
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Crawl $record): bool => $record->status === 'completed')
                    ->schema([
                        ViewEntry::make('folders')->hiddenLabel()->view('crawls.folders'),
                    ]),
                Section::make('Yapısal veri envanteri')
                    ->description('Sitede kullanılan şema türleri ve Google zengin sonuç kurallarına uygunluğu.')
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Crawl $record): bool => $record->status === 'completed')
                    ->schema([
                        ViewEntry::make('schema')->hiddenLabel()->view('crawls.schema'),
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
