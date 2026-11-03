<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** A random event of a run, fixed in the snapshot at the start (docs/18 §12.3): a sandstorm (the word only by ear), an oasis (a flask back), a bazaar (a hint for coins). */
enum RunEvent: string
{
    case Sandstorm = 'sandstorm';
    case Oasis = 'oasis';
    case Bazaar = 'bazaar';
}
