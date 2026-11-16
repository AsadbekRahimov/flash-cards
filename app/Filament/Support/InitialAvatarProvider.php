<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Panel avatar drawn locally: the first letter of the name on a dark square, as a `data:` SVG (allowed by the CSP,
 * docs/07 §8). Replaces Filament's ui-avatars.com, which is blocked by `img-src` and would leak staff names to a third
 * party. No queries and no external requests: it runs on every panel page.
 */
final class InitialAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $name = Filament::getNameForDefaultAvatar($record);

        $letter = preg_match('/[\p{L}\p{N}]/u', $name, $match) === 1 ? mb_strtoupper($match[0]) : '?';
        $letter = htmlspecialchars($letter, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#09090b"/>'
            .'<text x="32" y="32" text-anchor="middle" dominant-baseline="central" fill="#ffffff" '
            .'font-family="system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif" '
            .'font-size="32" font-weight="700">'.$letter.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
