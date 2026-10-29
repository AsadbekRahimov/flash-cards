<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** Why a solo session is finished (`practice_sessions.finish_reason`, docs/16 §2.5). */
enum FinishReason: string
{
    case Completed = 'completed';
    /** `expires_at` passed (lazily on a request or by `practice:close-expired`). */
    case Expired = 'expired';
    /** The learner opened a new session with `replace_active`. */
    case Replaced = 'replaced';
}
