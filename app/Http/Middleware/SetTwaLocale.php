<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the app locale of a TWA API request, so validation and error texts follow the
 * learner's interface language instead of `APP_LOCALE`.
 *
 *   - authenticated (`TwaAuth` ran first and put the learner into the request): the learner's locale
 *   - `POST /auth` (no learner yet): the first supported `Accept-Language` tag, else the default
 *
 * Bot and Filament have their own locale handling and do not use this middleware.
 */
final class SetTwaLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $learner = $request->attributes->get('learner');

        $locale = $learner instanceof Learner
            ? $this->locales->forLearner($learner)
            : $this->locales->fromAcceptLanguage($request->getLanguages());

        // setLocale() writes the config, the translator and dispatches LocaleUpdated (Carbon re-reads its translator
        // on it): ~0.2-4 ms per request. When the locale is already current there is nothing to switch.
        if (App::getLocale() !== $locale) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
