<?php

declare(strict_types=1);

namespace App\Domain\Polls\Exceptions;

use App\Domain\Polls\Services\PollEditor;
use RuntimeException;

/**
 * The form of `/admin` asked {@see PollEditor} for something a poll cannot be (docs/35 §1.5, O6). Nothing is written. `$reason` is a
 * stable code the panel words (`admin.polls.errors.<reason>`).
 */
final class PollEditException extends RuntimeException
{
    /** The poll went out: the question, the options, the picture, the answer rules and the audience no longer change. */
    public const LOCKED = 'locked';

    /** The poll is closed or stopped: only the internal title still changes. */
    public const FINISHED = 'finished';

    /** The internal title is empty or longer than {@see PollEditor::TITLE_MAX}. */
    public const TITLE = 'title';

    /** The question is empty or longer than {@see PollEditor::QUESTION_MAX} in a language. */
    public const QUESTION = 'question';

    /** Fewer than 2 or more than 10 options. */
    public const OPTIONS = 'options';

    /** An option is empty or longer than {@see PollEditor::LABEL_MAX} in a language. */
    public const LABEL = 'label';

    /** The limit of several answers is outside 1..the number of options. */
    public const MAX_CHOICES = 'max_choices';

    /** The picture is not a stored upload of the form (`polls/<random>.jpg|png` on the private disk). */
    public const IMAGE = 'image';

    /** A language, a course or a region of the audience that does not exist. */
    public const AUDIENCE = 'audience';

    /** The deadline is not in the future. */
    public const CLOSES_AT = 'closes_at';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, 'The poll cannot be saved: '.$reason.'.');
    }
}
