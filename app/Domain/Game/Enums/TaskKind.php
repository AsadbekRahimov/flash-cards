<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** The kind of an element of a run (docs/18 §12.0): five made from the words, four played through the handlers of the practice. */
enum TaskKind: string
{
    case TranslateFwd = 'translate_fwd';
    case TranslateBack = 'translate_back';
    case Listen = 'listen';
    case Spell = 'spell';
    case Gap = 'gap';
    case PairsRound = 'pairs_round';
    case Collocation = 'collocation';
    case Sentence = 'sentence';
    case Dictation = 'dictation';

    /** An element made from the words alone (no authored task of the practice behind it). */
    public function isWordTask(): bool
    {
        return match ($this) {
            self::TranslateFwd, self::TranslateBack, self::Listen, self::Spell, self::Gap => true,
            default => false,
        };
    }
}
