<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Learners\Services\ReminderQuizStore;

/**
 * `callback_data` of a reminder question's option: `rq1:<token>:<index>` — a versioned prefix like `onb1` / `pro1`, a
 * 10-character random token that ties the button to the question state of the learner, and the tapped index (0..3). 16 bytes
 * of the 64 Telegram allows. The data is NOT signed and says nothing about the right option: the server compares the
 * index with the state it kept ({@see ReminderQuizStore}).
 */
final class ReminderQuizCallback
{
    public const PREFIX = 'rq1';

    private const FORMAT = '~^rq1:([A-Za-z0-9]{10}):([0-3])$~D';

    public static function data(string $token, int $index): string
    {
        return self::PREFIX.':'.$token.':'.$index;
    }

    /** @return array{token: string, index: int}|null null for anything that is not exactly this format */
    public static function parse(string $data): ?array
    {
        if (preg_match(self::FORMAT, $data, $m) !== 1) {
            return null;
        }

        return ['token' => $m[1], 'index' => (int) $m[2]];
    }
}
