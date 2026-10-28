<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Site\Services\LandingPricing;
use App\Domain\Site\Services\SiteLinks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public marketing / legal pages. Static content only: no session, no cookies.
 * Locale: explicit `/{locale}/...` wins; otherwise `Accept-Language`, else the
 * default locale (the response then carries `Vary: Accept-Language`).
 */
final class PublicSiteController extends Controller
{
    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly SiteLinks $links,
        private readonly LandingPricing $pricing,
    ) {}

    public function home(Request $request, ?string $locale = null): Response
    {
        return $this->page($request, 'home', 'site.landing', $locale, fn (string $resolved): array => [
            'pricing' => $this->pricing->forLocale($resolved),
        ]);
    }

    public function offer(Request $request, ?string $locale = null): Response
    {
        return $this->page($request, 'offer', 'site.offer', $locale);
    }

    public function privacy(Request $request, ?string $locale = null): Response
    {
        return $this->page($request, 'privacy', 'site.privacy', $locale);
    }

    /** @param  (Closure(string): array<string, mixed>)|null  $extra  view data that depends on the resolved locale */
    private function page(Request $request, string $page, string $view, ?string $locale, ?Closure $extra = null): Response
    {
        $negotiated = ! $this->locales->isSupported($locale);
        $locale = $negotiated
            ? $this->locales->fromAcceptLanguage($request->getLanguages())
            : (string) $locale;

        $response = response()->view($view, [
            'locale' => $locale,
            'locales' => $this->locales->supported(),
            'page' => $page,
            'links' => $this->links,
            'botUrl' => $this->links->botUrl(),
            'contact' => $this->links->supportContact(),
            // The limits every page quotes (the meta description of all three; the plan cards of the landing).
            'limits' => $this->pricing->limits($locale),
            ...($extra === null ? [] : $extra($locale)),
        ]);

        $response->headers->set('Cache-Control', 'public, max-age=300');
        if ($negotiated) {
            $response->headers->set('Vary', 'Accept-Language');
        }

        return $response;
    }
}
