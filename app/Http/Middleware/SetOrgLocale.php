<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Organization;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `/org` cabinet speaks the organization's language (`organizations.locale`, ru / uz): runs
 * after `IdentifyTenant` (tenant middleware, persistent for Livewire updates). Outside a tenant
 * URL (login, password reset) the application default applies.
 */
final class SetOrgLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Organization && $this->locales->isSupported($tenant->locale)) {
            app()->setLocale($tenant->locale);
        }

        return $next($request);
    }
}
