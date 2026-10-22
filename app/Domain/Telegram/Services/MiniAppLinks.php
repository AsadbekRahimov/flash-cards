<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

/**
 * Direct links to the Mini App (`https://t.me/{bot}[/{short_name}]?startapp={param}`).
 *
 * A `web_app` inline button works only in a private chat with the bot; in groups, supergroups and
 * channels the Bot API rejects it. A plain URL button with a direct link opens the Mini App there,
 * and the target screen travels in `start_param` (base64url of the TWA path, decoded by the TWA router).
 */
final class MiniAppLinks
{
    /** Telegram limit of the `startapp` parameter. */
    public const MAX_START_PARAM_LENGTH = 512;

    /**
     * A private chat has a positive numeric id; groups / supergroups / channels are negative or `@name`.
     */
    public function isPrivateChat(int|string $chatId): bool
    {
        if (is_int($chatId)) {
            return $chatId > 0;
        }

        return ctype_digit($chatId) && (int) $chatId > 0;
    }

    /**
     * Direct link for a TWA URL (`{twa.base_url}/twa/...`), or null when it cannot be built: no bot
     * username configured, the URL is not inside the TWA, or the path does not fit into `start_param`.
     */
    public function forPath(string $twaUrl): ?string
    {
        $username = ltrim(trim((string) config('telegram.bot_username')), '@');
        if ($username === '') {
            return null;
        }

        $path = $this->pathFromTwaUrl($twaUrl);
        if ($path === null) {
            return null;
        }

        $param = $this->startParam($path);
        if (strlen($param) > self::MAX_START_PARAM_LENGTH) {
            return null;
        }

        $shortName = trim((string) config('telegram.mini_app_short_name'));

        return $shortName === ''
            ? "https://t.me/{$username}?startapp={$param}"
            : "https://t.me/{$username}/{$shortName}?startapp={$param}";
    }

    /** base64url without padding: only `[A-Za-z0-9_-]`, as Telegram requires. */
    public function startParam(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    /**
     * The part of a TWA URL after `{base_url}/twa`, kept with its query string
     * (`.../twa/training/15` -> `/training/15`, `.../twa/?from=x` -> `/?from=x`).
     */
    public function pathFromTwaUrl(string $twaUrl): ?string
    {
        $prefix = rtrim((string) config('twa.base_url'), '/').'/twa';
        if (! str_starts_with($twaUrl, $prefix)) {
            return null;
        }

        $rest = substr($twaUrl, strlen($prefix));
        if ($rest === '') {
            return '/';
        }

        return match ($rest[0]) {
            '/' => $rest,
            '?' => '/'.$rest,
            default => null,
        };
    }
}
