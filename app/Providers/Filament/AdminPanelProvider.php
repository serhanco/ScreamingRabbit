<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ApplyUserPalette;
use App\Http\Middleware\RunPendingMigrations;
use App\Support\Palette;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\UserMenuPosition;
use Filament\Forms\Components\Radio;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Component;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('ScreamingRabbit')
            ->brandLogo(fn () => view('components.brand-logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->renderHook(PanelsRenderHook::FOOTER, fn () => Blade::render('<x-brand-footer />'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => Blade::render('<x-brand-footer />'))
            // No top bar: the logo, navigation and user menu all live in the collapsible sidebar to save vertical space.
            ->topbar(false)
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->renderHook(PanelsRenderHook::SIDEBAR_LOGO_BEFORE, fn () => Blade::render('<x-brand-mark-collapsed />'))
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->colors(Palette::colors(null))
            ->userMenuItems([
                'palette' => fn (): Action => $this->paletteAction(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                RunPendingMigrations::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ApplyUserPalette::class,
            ]);
    }

    // User menu entry that lets each user pick a colour palette; saved on the user and applied on the next page load.
    private function paletteAction(): Action
    {
        return Action::make('palette')
            ->label('Renk paleti')
            ->icon(Heroicon::OutlinedSwatch)
            ->modalHeading('Renk paleti')
            ->modalDescription('Panelin renklerini değiştirir; düzen aynı kalır. Seçim yalnızca senin hesabın için geçerlidir.')
            ->modalSubmitActionLabel('Uygula')
            ->modalWidth(Width::Medium)
            ->fillForm(fn (): array => ['palette' => Palette::resolve(auth()->user()->color_palette)])
            ->schema([
                Radio::make('palette')
                    ->hiddenLabel()
                    ->options(Palette::options())
                    ->required(),
            ])
            ->action(function (array $data, Component $livewire): void {
                auth()->user()->forceFill(['color_palette' => Palette::resolve($data['palette'])])->save();

                $livewire->redirect(request()->header('Referer') ?: filament()->getUrl());
            });
    }
}
