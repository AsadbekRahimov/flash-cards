<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Learners\Services\LearnerRegistrar;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\TelegramGroup;
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
     * Logging in is the person's own action: it revives a soft-deleted learner and
     * bumps `last_seen_at` (docs/07 §10).
     *
     * @param  array<string, mixed>  $tgUser  `user` object of the validated initData
     * @param  string|null  $contextType  `training` | `exam` (analytics hint only)
     * @return array{0: Learner, 1: TelegramGroup|null} the learner and the group of the opened session, if any
     */
    public function resolve(array $tgUser, ?string $contextType, ?int $sessionId): array
    {
        $group = $this->contextGroup($contextType, $sessionId);
        $source = $group !== null ? self::SOURCE_TWA_GROUP : null;

        return [$this->registrar->fromTelegramUser($tgUser, $source), $group];
    }

    private function contextGroup(?string $contextType, ?int $sessionId): ?TelegramGroup
    {
        if ($contextType === null || $sessionId === null) {
            return null;
        }

        $groupId = match ($contextType) {
            'training' => TrainingSession::query()->whereKey($sessionId)->value('telegram_group_id'),
            'exam' => ExamSession::query()->whereKey($sessionId)->value('telegram_group_id'),
            default => null,
        };

        return is_numeric($groupId) ? TelegramGroup::query()->find((int) $groupId) : null;
    }
}
