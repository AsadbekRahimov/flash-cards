<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Support\BotButton;
use App\Domain\Telegram\Support\TwaScreen;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The buttons under the messages of the bot (docs/36 §3б): the ONLY place that reads `twa.base_url` to address a Mini App screen
 * (besides {@see MiniAppLinks}, `telegram:setup-bot` and {@see BotMenuBuilder}; a guard test keeps it so), and the one that turns
 * a list of buttons into a `reply_markup`.
 *
 * The rule (docs/04 B.4b): a message after which an action makes sense has exactly ONE main button; a second one only for a
 * choice (Accept / Decline) or a refusal of the mailing. The texts of the buttons are `lang/{ru,uz}/buttons.php`.
 *
 * - `web_app` buttons work only in a private chat with the bot: {@see keyboard()} makes them a `startapp` link anywhere else (as
 *   {@see IrazasyedTelegramClient::sendWebAppButton()} does), and the plain address when no bot username is configured.
 * - `callback_data` is at most 64 bytes (Telegram's limit): a longer one is a bug, refused here rather than by the Bot API.
 * - `from=<kind of message>` goes into `$query`: the analytics of the Mini App sees which message brought the learner in.
 */
final class BotButtons
{
    /** Telegram's limit of `callback_data`, in bytes. */
    public const MAX_CALLBACK_BYTES = 64;

    public function __construct(private readonly MiniAppLinks $miniApp = new MiniAppLinks) {}

    /**
     * `{twa.base_url}/twa{path}[?query]`: `{name}` of the path is replaced by `$params[name]` (URL-encoded).
     *
     * @param  array<string, int|string>  $params
     * @param  array<string, int|string>  $query
     *
     * @throws InvalidArgumentException when a parameter of the path is missing
     */
    public function twaUrl(TwaScreen $screen, array $params = [], array $query = []): string
    {
        $path = (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($params, $screen): string {
            if (! array_key_exists($m[1], $params) || (string) $params[$m[1]] === '') {
                throw new InvalidArgumentException("The screen {$screen->name} needs the parameter `{$m[1]}`.");
            }

            return rawurlencode((string) $params[$m[1]]);
        }, $screen->value);

        $url = rtrim((string) config('twa.base_url'), '/').'/twa'.$path;

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A button that opens a screen of the Mini App (`web_app`; a `startapp` link outside a private chat, see {@see keyboard()}).
     *
     * @param  array<string, int|string>  $params
     * @param  array<string, int|string>  $query
     */
    public function open(string $text, TwaScreen $screen, array $params = [], array $query = []): BotButton
    {
        return new BotButton($text, BotButton::KIND_WEB_APP, $this->twaUrl($screen, $params, $query));
    }

    /**
     * @throws InvalidArgumentException when `$data` is empty or longer than 64 bytes
     */
    public function callback(string $text, string $data): BotButton
    {
        if ($data === '' || strlen($data) > self::MAX_CALLBACK_BYTES) {
            throw new InvalidArgumentException('callback_data must be 1-'.self::MAX_CALLBACK_BYTES.' bytes, got '.strlen($data).'.');
        }

        return new BotButton($text, BotButton::KIND_CALLBACK, $data);
    }

    public function url(string $text, string $url): BotButton
    {
        return new BotButton($text, BotButton::KIND_URL, $url);
    }

    /**
     * The `reply_markup` of a message to `$chatId`: one row per inner list. A `web_app` button becomes a URL button with the
     * `startapp` link of its screen outside a private chat.
     *
     * @param  list<list<BotButton>>  $rows
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function keyboard(int|string $chatId, array $rows): array
    {
        $private = $this->miniApp->isPrivateChat($chatId);

        return ['inline_keyboard' => array_map(
            fn (array $row): array => array_map(fn (BotButton $button): array => $this->render($button, $private), $row),
            $rows,
        )];
    }

    /**
     * The keyboard of one button: the usual case under the rule of one main button.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function single(int|string $chatId, BotButton $button): array
    {
        return $this->keyboard($chatId, [[$button]]);
    }

    /** @return array<string, mixed> */
    private function render(BotButton $button, bool $private): array
    {
        return match (true) {
            $button->isWebApp() && $private => ['text' => $button->text, 'web_app' => ['url' => $button->value]],
            $button->isWebApp() => ['text' => $button->text, 'url' => $this->groupLink($button->value)],
            $button->kind === BotButton::KIND_CALLBACK => ['text' => $button->text, 'callback_data' => $button->value],
            default => ['text' => $button->text, 'url' => $button->value],
        };
    }

    private function groupLink(string $twaUrl): string
    {
        $link = $this->miniApp->forPath($twaUrl);
        if ($link !== null) {
            return $link;
        }

        // No TELEGRAM_BOT_USERNAME (or a path too long for `startapp`): the plain page still opens in a browser.
        Log::warning('telegram.mini_app_link_unavailable', ['reason' => 'bot_username_missing_or_url_not_in_twa']);

        return $twaUrl;
    }
}
