<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
 * - A comment / a key is used rather than something the page renders: nothing changes for the user, nothing for
 *   Blade or Alpine.
 *
 * Which responses: only the paths of {@see self::PATHS} - the same paths as the direct-fastcgi `location` of
 * docker/nginx/{prod,coolify,default}.conf, where nginx now compresses the dynamic output
 * (tests/Unit/DeployInfraConfigTest.php keeps the two lists equal). Left alone: `StreamedResponse` (Filament CSV
 * exports, `wire:stream`), `BinaryFileResponse` (livewire.min.js, downloads), redirects, 1xx/204/304 and empty
 * bodies, HEAD, anything already `Content-Encoding`d, every content type but HTML and JSON. The TWA API (/api/*)
 * carries no CSRF token and does not reflect the JWT, the public site pages are cached with `Cache-Control: public`
 * (a random body would defeat the cache) - both are outside the paths. `Content-Length`, when a response sets one, is
 * corrected to the new length.
 *
 * Registered globally in bootstrap/app.php (appended: the innermost global middleware, so it runs on the way out
 * right after the route middleware and nothing after it replaces the body - the outer global middleware only touch
 * headers). No state in the instance: safe under Octane.
 *
 * What it does and does not give: the noise raises the number of requests an attacker needs by orders of magnitude
 * (averaging over the noise is possible in theory, in practice it needs tens of thousands of requests per guessed
 * character); it is not a proof. The real defences remain: `SameSite=Lax` on the session cookie (the attacker's page
 * cannot make the browser send the panel session on a cross-site POST - and BREACH needs the victim's session on
 * thousands of attacker-controlled requests), 2FA on both panels, and the CSRF token being useless without the
 * session. Per-response masking of the CSRF token would remove the secret instead of hiding it; it is not done here.
 */
final class RandomizeResponseLength
{
    /**
     * The paths nginx compresses dynamically and this middleware pads. An entry ending in `/` is a path segment
     * (`admin/` matches `admin` and `admin/...`, not `administration`); any other entry is a raw prefix
     * (`livewire-` matches `livewire-<hash>/update`). {@see self::nginxLocationRegex()} renders the nginx form.
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

        if (! self::appliesTo($request->path())) {
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

        $response->setContent($padded);

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($padded));
        }

        return $response;
    }

    /** `$path` as {@see Request::path()} gives it: no leading slash, `/` for the root. */
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
