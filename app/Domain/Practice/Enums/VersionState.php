<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** The state of one version of a task (`practice_item_versions.state`, docs/16 §2.3): draft -> reviewed -> published -> superseded. */
enum VersionState: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Published = 'published';
    /** Replaced by a newer published version; the sessions and matches that already hold it keep playing it. */
    case Superseded = 'superseded';
}
