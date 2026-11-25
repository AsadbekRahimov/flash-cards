<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\Support;

use App\Domain\Engagement\DTO\ScoreCorrection;
use App\Domain\Engagement\Exceptions\ScoreCorrectionException;
use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\Learner;
use App\Models\User;
use App\Policies\LearnerPolicy;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * The corrections of a rating day in /admin (docs/32 §6.4, S15): «Аннулировать день», «Аннулировать начисление», «Восстановить» on
 * the "Очки по дням" tab of a learner and on the journal of a day. The decision itself is {@see ScoreCorrectionService} (locks,
 * the recount of the day, the audit row); here only the right is checked again before every call (`update` of the learner — an
 * active admin, {@see LearnerPolicy}), the reason is asked, and the result or the refusal (`ScoreCorrectionException::$errorCode`) is
 * worded as a notification.
 */
final class ScoreCorrectionActions
{
    /** The required reason of a void: kept with the credits and in the audit row. */
    public static function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label(__('admin.learners.score_days.fields.reason'))
            ->helperText(__('admin.learners.score_days.fields.reason_help', ['max' => ScoreCorrectionService::MAX_REASON_LENGTH]))
            ->required()
            ->maxLength(ScoreCorrectionService::MAX_REASON_LENGTH)
            ->rows(3);
    }

    /**
     * Voids (`$reason` given) or restores (`$reason` null) the credits of the learner's day — `$creditIds` null = every credit of the
     * day — and tells the result. Null when refused.
     *
     * @param  list<int>|null  $creditIds
     */
    public static function run(Learner $learner, string $ratingDay, ?array $creditIds, ?string $reason): ?ScoreCorrection
    {
        Gate::authorize('update', $learner);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $service = app(ScoreCorrectionService::class);

        try {
            $result = $reason === null
                ? $service->restore($learner, $ratingDay, $creditIds, $user, request()->ip())
                : $service->void($learner, $ratingDay, $creditIds, $reason, $user, request()->ip());
        } catch (ScoreCorrectionException $e) {
            Notification::make()->danger()->title(AdminErrors::text('learners.score_days', $e->errorCode, ['max' => ScoreCorrectionService::MAX_REASON_LENGTH]))->send();

            return null;
        }

        self::notify($result, $reason !== null);

        return $result;
    }

    private static function notify(ScoreCorrection $result, bool $voided): void
    {
        if (! $result->changed()) {
            Notification::make()->info()->title(__('admin.learners.score_days.notifications.unchanged'))->send();

            return;
        }

        $body = __('admin.learners.score_days.notifications.points', [
            'week_before' => $result->weekPointsBefore,
            'week_after' => $result->weekPointsAfter,
            'month_before' => $result->monthPointsBefore,
            'month_after' => $result->monthPointsAfter,
        ]);
        if ($result->weekFrozen) {
            $body .= ' '.__('admin.learners.score_days.notifications.week_frozen');
        }

        Notification::make()
            ->success()
            ->title(__($voided ? 'admin.learners.score_days.notifications.voided' : 'admin.learners.score_days.notifications.restored', ['count' => count($result->changedCreditIds)]))
            ->body($body)
            ->send();
    }
}
