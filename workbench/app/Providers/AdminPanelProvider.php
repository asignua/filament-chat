<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Asignua\FilamentChat\FilamentChatPlugin;
use Asignua\FilamentChat\Support\References\ReferenceType;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Workbench\App\Filament\Resources\Notes\NoteResource;
use Workbench\App\Filament\Resources\Tokens\TokenResource;
use Workbench\App\Filament\Resources\Users\UserResource;
use Workbench\App\Models\Team;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // `WORKBENCH_TENANCY=1 vendor/bin/testbench serve` — the same panel with teams as tenants.
            ->when(env('WORKBENCH_TENANCY'), fn (Panel $panel): Panel => $panel->tenant(Team::class, slugAttribute: 'slug'))
            ->databaseNotifications()
            // The demo resources have no team column, so the tenancy variant of the panel has none.
            ->resources(env('WORKBENCH_TENANCY') ? [] : [NoteResource::class, UserResource::class, TokenResource::class])
            ->plugin(FilamentChatPlugin::make()
                ->users(fn (Builder $query) => $query->where('is_active', true))
                ->references(env('WORKBENCH_TENANCY') ? [] : [ReferenceType::resource(NoteResource::class)->color('warning')]))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                // Laravel 13 renamed VerifyCsrfToken to PreventRequestForgery; the string is the Laravel 12 fallback.
                class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
