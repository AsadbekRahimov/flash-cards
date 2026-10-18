<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Learners\Services\LearnerRegistrar;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\TrainingSession;

/**
 * Maps validated initData to a learner. Identity is the Telegram user alone:
 * a learner is created on the first login (e.g. the web_app button in a group
 * was tapped before any /start in DM). Whether the learner may open a given
 * session is decided on that session's routes (policies), never here — so
 * /auth has no 409 `session_context_required` any more.
 */
final class LearnerContextResolver
{
    public const SOURCE_TWA_GROUP = 'twa_group';

    public function __construct(private readonly LearnerRegistrar $registrar) {}

    /**
     * @param  array<string, mixed>  $tgUser  `user` object of the validated initData
     * @param  string|null  $contextType  `training` | `exam` (analytics hint only)
     */
    public function resolve(array $tgUser, ?string $contextType, ?int $sessionId): Learner
    {
        $source = $this->hasGroupContext($contextType, $sessionId) ? self::SOURCE_TWA_GROUP : null;

        return $this->registrar->fromTelegramUser($tgUser, $source);
    }

    private function hasGroupContext(?string $contextType, ?int $sessionId): bool
    {
        if ($contextType === null || $sessionId === null) {
            return false;
        }

        $groupId = match ($contextType) {
            'training' => TrainingSession::query()->whereKey($sessionId)->value('telegram_group_id'),
            'exam' => ExamSession::query()->whereKey($sessionId)->value('telegram_group_id'),
            default => null,
        };

        return is_numeric($groupId);
    }
}
