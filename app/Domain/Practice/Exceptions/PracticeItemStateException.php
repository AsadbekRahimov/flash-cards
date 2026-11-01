<?php

declare(strict_types=1);

namespace App\Domain\Practice\Exceptions;

use RuntimeException;

/**
 * Why a task could not move to the next stage of its life (docs/16 §2.2): the move does not fit where the task is now.
 * 409 whatever the caller; `errorCode` is the stable code the admin panel words in Russian (AdminErrors).
 *
 *   item_archived      the task is in the archive: restore it before editing, reviewing or publishing it
 *   item_not_archived  "restore" of a task that is not archived
 *   not_draft          "reviewed" for a version that is already published (nothing to check)
 *   not_reviewed       "publish" for a version nobody has marked as reviewed (a draft is never published directly)
 *   no_version         the task has no version to move (a task is made with its first version, so this means a damaged row)
 *   item_gone          the task was deleted while the admin looked at it
 *   version_changed    the current version is not the one the admin was looking at (an import or another editor wrote a newer
 *                      one): "reviewed" / "publish" would act on a text nobody has seen
 */
final class PracticeItemStateException extends RuntimeException
{
    public const ARCHIVED = 'item_archived';

    public const NOT_ARCHIVED = 'item_not_archived';

    public const NOT_DRAFT = 'not_draft';

    public const NOT_REVIEWED = 'not_reviewed';

    public const NO_VERSION = 'no_version';

    public const GONE = 'item_gone';

    public const VERSION_CHANGED = 'version_changed';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function archived(): self
    {
        return new self(self::ARCHIVED, 'The task is archived: restore it first.');
    }

    public static function notArchived(): self
    {
        return new self(self::NOT_ARCHIVED, 'The task is not archived.');
    }

    public static function notDraft(): self
    {
        return new self(self::NOT_DRAFT, 'The current version is not a draft: there is nothing to mark as reviewed.');
    }

    public static function notReviewed(): self
    {
        return new self(self::NOT_REVIEWED, 'Only a version marked as reviewed can be published.');
    }

    public static function noVersion(): self
    {
        return new self(self::NO_VERSION, 'The task has no version.');
    }

    public static function gone(): self
    {
        return new self(self::GONE, 'The task no longer exists.');
    }

    public static function versionChanged(): self
    {
        return new self(self::VERSION_CHANGED, 'The task was changed while it was open: open it again.');
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
