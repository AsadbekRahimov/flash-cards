<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `learner_lexemes.confirmed_source` (docs/32 §2.2): where a new word was first confirmed; `legacy` — the one-off backfill of the words learnt before. */
enum LexemeConfirmSource: string
{
    case Training = 'training';
    case Game = 'game';
    case Legacy = 'legacy';
}
