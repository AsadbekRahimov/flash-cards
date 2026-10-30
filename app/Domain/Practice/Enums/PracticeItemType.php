<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** The type of an authored task (`practice_items.type`, docs/16 §2.2). Pairs have no task: they are made of `words`. */
enum PracticeItemType: string
{
    case Collocation = 'collocation';
    case Sentence = 'sentence';
    case Dictation = 'dictation';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
