<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** The type of an authored task (`practice_items.type`, docs/16 §2.2). Pairs have no task: they are made of `words`. */
enum PracticeItemType: string
{
    case Collocation = 'collocation';
    case Sentence = 'sentence';
    case Dictation = 'dictation';
    /** "Word form" (docs/16 §5.6): a sentence with a gap and a base word, the learner types the form. */
    case WordForm = 'word_form';
    /** "Find the mistake" (docs/16 §5.7): a sentence with one wrong word, found and then corrected. */
    case ErrorHunt = 'error_hunt';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /**
     * The types the game «Караван» plays (docs/18 §12): the three it was designed for. The newer ones need a task kind, a review
     * and an SRS rule of their own in the run first.
     *
     * @return list<self>
     */
    public static function inGame(): array
    {
        return [self::Collocation, self::Sentence, self::Dictation];
    }
}
