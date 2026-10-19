<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/** What DeckImportParser made of a text: the accepted rows and the lines it threw away (with the reason). */
final readonly class DeckParseResult
{
    public const REASON_EMPTY_TRANSLATION = 'empty_translation';

    public const REASON_JUNK = 'junk';

    public const REASON_TOO_LONG = 'too_long';

    public const REASON_DUPLICATE = 'duplicate';

    public const REASON_OVER_LIMIT = 'over_limit';

    /**
     * @param  list<DeckRow>  $rows
     * @param  list<array{line:int, reason:string}>  $rejected  `line` is 1-based, as the learner sees it
     */
    public function __construct(public array $rows, public array $rejected) {}
}
