<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BREACH noise for the compressed panel responses (docs/07 §8 «Сжатие и BREACH», docs/DEVOPS_GUIDE «Compression and BREACH»).
 *
 * nginx compresses the HTML and JSON of the panels (/admin, /org, /2fa, /livewire-*) since 16.11.2026: a list page is
 * 210-260 KB of HTML (24-30 KB gzipped) on every click and every Livewire round trip, and `gzip off` cost 350-400 ms a
 * page on a mobile link. The price of compression is BREACH: a page that carries a secret (the CSRF token in Filament's
 * meta tag and in `data-csrf` of the Livewire script) and reflects attacker-chosen input lets the attacker guess the
 * secret byte by byte from the compressed length - a correct guess compresses one byte better. This middleware takes
 * the signal away: every response gets a random amount of random bytes, so the length no longer tells a right guess
 * from a wrong one. It is the Heal-the-BREACH idea (Palacios et al., 2022; Django 4.2 `GZipMiddleware` puts 0-99
 * random bytes into the gzip *header*); here the noise is inside the *body*, because the body is what survives
 * Cloudflare re-compressing gzip into brotli between the origin and the browser (docs/23 §4) - a gzip-header trick
 * would not.
 *
 * Which responses get the noise - either of two conditions, both independent of how the attacker spells the URL:
 * 1. The request has a session (`$request->hasSession()`, set by `StartSession`). The secret exists only in the
 *    responses of session routes - the panel pages and login pages, /2fa/*, `POST /livewire-<hash>/update` - and this
 *    covers any session route added later. The public site, the Telegram and payment webhooks, /billing/return and
 *    /health/ready strip `StartSession` (routes/web.php, routes/health.php): no session, no secret, no noise - and the
 *    public pages are cached with `Cache-Control: public`, where a random body would defeat the cache. /api/* has no
 *    session (JWT; the token is not reflected).
 * 2. The path, normalised the way the router matches it (`rawurldecode(getPathInfo())`, repeated slashes merged,
 *    trimmed), is under {@see self::PATHS} - the panel prefixes nginx hands to PHP directly. This catches a panel
 *    response produced before `StartSession` ran (a 404 of the router, an exception in an earlier middleware).
 *    A raw `Request::path()` would NOT do: `/%61dmin/courses` and `/admin%2Fcourses` reach the `admin/courses` route
 *    (the router decodes), nginx compresses them (it matches the decoded URI), and the raw path starts with `%61`.
 *    `tests/Unit/DeployInfraConfigTest.php` keeps PATHS equal to the nginx location regex.
 *
 * Where and what:
 * - HTML: an HTML comment `<!--…-->` appended after the end of the document. The HTML parser attaches a comment that
 *   follows `</html>` to the Document, not to `<body>`: `wire:navigate` (Filament SPA mode) swaps `document.body` and
 *   merges `<head>` from the fetched HTML and never sees it, Livewire morphs component roots and never sees it either.
 * - JSON (the Livewire `update` endpoint - `{"components":[…],"assets":[…]}`): one extra top-level key `_pad`,
 *   spliced into the text before the closing brace, so the original text is byte for byte unchanged (no decode and
 *   re-encode: floats, escaping and key order stay as the controller emitted them). Livewire's client does
 *   `JSON.parse(body)` and reads `components` and `assets` by name (livewire/js/request/index.js,
 *   features/supportScriptsAndAssets.js); an unknown key is ignored. Only a JSON *object* body is touched: there is no
 *   place in a bare array or scalar for a key.
 * - The noise is base64 of CSPRNG bytes (`random_bytes`): 6 bits of entropy per character, so deflate and brotli
 *   cannot shrink it below ~0.75 byte per character and spend 0.75-1 byte each (Huffman codes of the HTML's literals
 *   are 5-9 bits). The length is uniform in [32, 160] characters (`random_int`): 128 characters of spread give
 *   ~100-128 bytes of spread in the compressed size under gzip and under brotli, with the character values adding
 *   their own jitter. Whitespace or a repeated character would NOT do: deflate codes a run in a few bytes whatever its
 *   length, and the compressed size would barely move. The cost is <= 165 bytes a response against 24-30 KB.
 * - `original` of the Illuminate response (the View / the array a test inspects with `assertViewHas`) is kept:
 *   `Illuminate\Http\Response::setContent()` would overwrite it with the padded string.
 *
 * Left alone whatever the path: `StreamedResponse` (Filament CSV exports, `wire:stream`), `BinaryFileResponse`
 * (livewire.min.js, downloads), redirects, 1xx/204/304 and empty bodies, HEAD, anything already `Content-Encoding`d,
 * every content type but HTML and JSON. `Content-Length`, when a response sets one, is corrected to the new length.
 *
 * Registered globally in bootstrap/app.php (appended: the innermost global middleware, so it runs on the way out
 * right after the route middleware and nothing after it replaces the body - the outer global middleware only touch
 * headers). No state in the instance: safe under Octane.
 *
 * What it does and does not give. Against bounded uniform noise the attacker's best move is not the mean but the
 * MINIMUM compressed length over repeated requests of the same candidate: the minimum settles at the true length plus
 * the smallest noise, and one request in 129 hits that floor, so seeing it with confidence 1-δ takes about
 * 129·ln(1/δ) ≈ 600 requests per candidate (δ = 1 %), ~37 000 per character of a 62-symbol token, ~1.5 million for a
 * 40-character token - all of them issued by the victim's browser while the session lives, and the floor is blurred
 * further by the per-character jitter of the codes. A cost multiplier, not a proof. The real defences remain:
 * `SameSite=Lax` on the session cookie (the attacker's page cannot make the browser send the panel session on a
 * cross-site POST, and BREACH needs the victim's session on every one of those requests), 2FA on both panels, and the
 * CSRF token being useless without the session. Per-response masking of the CSRF token would remove the secret
 * instead of hiding it; it is not done here.
 */
final class RandomizeResponseLength
{
    /**
     * The panel paths nginx compresses dynamically (its direct-fastcgi location) and this middleware pads even without
     * a session. An entry ending in `/` is a path segment (`admin/` matches `admin` and `admin/...`, not
     * `administration`); any other entry is a raw prefix (`livewire-` matches `livewire-<hash>/update`).
     * {@see self::nginxLocationAlternatives()} renders the nginx form.
     *
     * @var list<string>
     */
    public const array PATHS = ['admin/', 'org/', '2fa/', 'livewire-'];

    public const int MIN_LENGTH = 32;

    public const int MAX_LENGTH = 160;

    public const string JSON_KEY = '_pad';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $request->hasSession() && ! self::appliesTo(self::normalizePath($request->getPathInfo()))) {
            return $response;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        if ($request->isMethod('HEAD')
            || $response->isInformational()
            || $response->isRedirection()
            || $response->isEmpty()
            || $response->headers->has('Content-Encoding')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        $padded = match (true) {
            str_contains($contentType, 'text/html') => $content.'<!--'.self::noise().'-->',
            str_contains($contentType, 'application/json') => self::padJsonObject($content),
            default => null,
        };

        if ($padded === null) {
            return $response;
        }

        self::replaceContent($response, $padded);

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($padded));
        }

        return $response;
    }

    /**
     * The path the way the router sees it: percent-decoded (`%61dmin` -> `admin`, `%2F` -> `/`), repeated slashes
     * merged (nginx does the same before matching its locations), no leading or trailing slash; `` for the root.
     */
    public static function normalizePath(string $pathInfo): string
    {
        $decoded = preg_replace('#/{2,}#', '/', rawurldecode($pathInfo)) ?? '';

        return trim($decoded, '/');
    }

    /** `$path` normalised by {@see self::normalizePath()} (or {@see Request::path()} for an already plain URL). */
    public static function appliesTo(string $path): bool
    {
        $segments = $path.'/';

        foreach (self::PATHS as $prefix) {
            if (str_starts_with($segments, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Random base64 text of a random length in [MIN_LENGTH, MAX_LENGTH]. The alphabet (`A-Za-z0-9+/`, no `=`: the
     * cut is before any padding) is safe inside an HTML comment (no `-`, no `>`) and inside a JSON string (no `"`,
     * no `\`).
     */
    public static function noise(): string
    {
        $length = random_int(self::MIN_LENGTH, self::MAX_LENGTH);

        return substr(base64_encode(random_bytes(max(1, intdiv($length * 3 + 3, 4)))), 0, $length);
    }

    /**
     * The alternatives of the nginx `location ~` regex for {@see self::PATHS} (`admin(?:/|$)|…|livewire-`);
     * the test joins them with the non-panel paths that share the location and compares with the conf files.
     *
     * @return list<string>
     */
    public static function nginxLocationAlternatives(): array
    {
        return array_map(
            static fn (string $prefix): string => str_ends_with($prefix, '/')
                ? substr($prefix, 0, -1).'(?:/|$)'
                : $prefix,
            self::PATHS,
        );
    }

    /** Sets the body and keeps `original` (the View or array of an Illuminate response), which setContent() would replace. */
    private static function replaceContent(Response $response, string $padded): void
    {
        if ($response instanceof IlluminateResponse || $response instanceof JsonResponse) {
            $original = $response->original;
            $response->setContent($padded);
            $response->original = $original;

            return;
        }

        $response->setContent($padded);
    }

    /** `{…}` -> `{…,"_pad":"…"}`; `{}` -> `{"_pad":"…"}`; anything that is not a JSON object is left as it is (null). */
    private static function padJsonObject(string $content): ?string
    {
        $object = trim($content);
        if (! str_starts_with($object, '{') || ! str_ends_with($object, '}')) {
            return null;
        }

        // The last member of an object ends in `"`, a digit, `}`, `]` or a letter of true/false/null - never in `{`,
        // so a `{` right before the closing brace means the object is empty.
        $members = rtrim(substr($object, 0, -1));
        $separator = str_ends_with($members, '{') ? '' : ',';

        return $members.$separator.'"'.self::JSON_KEY.'":"'.self::noise().'"}';
    }
}
