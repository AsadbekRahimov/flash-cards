<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Telegram\Handlers\CupCallbackHandler;
use App\Domain\Telegram\Services\BotButtons;

/**
 * `callback_data` of the buttons of the friends' cup in a group (docs/33 §3.2, docs/04 B.16): `cup:new` («Создать кубок друзей»),
 * `cup:pub:{id}` («Опубликовать») and `cup:cancel:{id}` («Отменить») - at most 27 bytes of the 64 Telegram allows; the buttons are
 * made through {@see BotButtons}, which refuses anything longer.
 *
 * The data is NOT signed and asserts nothing: the id only finds the cup, the right to publish or cancel is a fresh `getChatMember` of
 * `from.id` on the server ({@see CupCallbackHandler}). Matched strictly, to the end of the string (`\z`: a trailing newline is not "the
 * end"); an id is a positive integer without leading zeros that fits a bigint.
 */
final class CupCallback
{
    public const PREFIX = 'cup';

    public const NEW = 'new';

    public const PUBLISH = 'pub';

    public const CANCEL = 'cancel';

    private const NEW_FORMAT = '~\Acup:new\z~';

    private const CUP_FORMAT = '~\Acup:(pub|cancel):([1-9][0-9]{0,17})\z~';

    public static function create(): string
    {
        return self::PREFIX.':'.self::NEW;
    }

    public static function publish(int $cupId): string
    {
        return self::PREFIX.':'.self::PUBLISH.':'.$cupId;
    }

    public static function cancel(int $cupId): string
    {
        return self::PREFIX.':'.self::CANCEL.':'.$cupId;
    }

    /** @return array{action: 'new'|'pub'|'cancel', cup_id: int|null}|null null for anything that is not exactly this format */
    public static function parse(string $data): ?array
    {
        if (preg_match(self::NEW_FORMAT, $data) === 1) {
            return ['action' => self::NEW, 'cup_id' => null];
        }
        if (preg_match(self::CUP_FORMAT, $data, $m) !== 1) {
            return null;
        }

        return ['action' => $m[1] === self::PUBLISH ? self::PUBLISH : self::CANCEL, 'cup_id' => (int) $m[2]];
    }
}
