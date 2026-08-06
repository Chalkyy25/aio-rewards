<?php

namespace App\Providers\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * AIO Rewards administration panel.
 *
 * - Path `/admin`; login enabled.
 * - Multi-factor (TOTP) authentication is registered as REQUIRED for every
 *   panel user; individual users must complete enrolment on first login.
 * - Panel access is additionally gated in User::canAccessPanel() to the
 *   support / admin / super_admin roles.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->multiFactorAuthentication(
                AppAuthentication::make(),
                isRequired: fn (?\App\Models\User $user): bool => $user?->requiresPanelMfa() ?? false,
            )
            ->brandName(config('app.name'))
            ->brandLogo(fn () => asset('images/aio-media-logo-light.png'))
            ->darkModeBrandLogo(fn () => asset('images/aio-media-logo-dark.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/aio-favicon.png'))
            ->colors([
                'primary' => Color::Slate,
            ])
            // Operations Centre is the default landing page after login.
            ->homeUrl(fn () => \App\Filament\Resources\OperationsItemResource::getUrl('index'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
                \App\Filament\Widgets\OperationsBellWidget::class,
                \App\Filament\Widgets\OperationsOverviewWidget::class,
                \App\Filament\Widgets\RewardsOverviewWidget::class,
                \App\Filament\Widgets\RecentOrdersWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
