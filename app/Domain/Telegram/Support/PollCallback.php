<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Polls\Services\PollVoteService;
use App\Domain\Telegram\Services\BotButtons;

/**
 * `callback_data` of a poll button (docs/04 B.5b, docs/35 §1.5): `pl1:<token>:<action>` — a versioned prefix like `rq1`, the
 * 12-character token of the delivery (`poll_deliveries.token`) and the action: the position of an option `0..9`, `s` («Отправить»,
 * several answers) or `c` («Изменить ответ»). 19 bytes of the 64 Telegram allows; the buttons are made through {@see BotButtons},
 * which refuses anything longer.
 *
 * The data is NOT signed and asserts nothing: the token only finds the delivery, the right to answer is `from.id`
 * ({@see PollVoteService}). Matched strictly, to the end of the string (`\z`: a trailing newline is not "the end").
 */
final class PollCallback
{
    public const PREFIX = 'pl1';

    public const SUBMIT = 's';

    public const CHANGE = 'c';

    private const FORMAT = '~\Apl1:([A-Za-z0-9]{12}):([0-9]|s|c)\z~';

    public static function data(string $token, int|string $action): string
    {
        return self::PREFIX.':'.$token.':'.$action;
    }

    /** @return array{token: string, action: string}|null null for anything that is not exactly this format */
    public static function parse(string $data): ?array
    {
        if (preg_match(self::FORMAT, $data, $m) !== 1) {
            return null;
        }

        return ['token' => $m[1], 'action' => $m[2]];
    }
}
