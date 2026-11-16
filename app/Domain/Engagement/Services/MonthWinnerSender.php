<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Listeners\Engagement\SendMonthWinnerMessages;
use App\Models\LeagueMonth;
use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The DM "you took place N of the rating of the month — P points. Your prize: X. We will contact you in Telegram" to the
 * winners of a confirmed month (docs/13 §7.6): the body of {@see SendMonthWinnerMessages}, which hangs on
 * {@see LeagueMonthConfirmed}. The prize is the one of the winner's place in the winner's language; a place without a
 * prize (or a month without prizes) gets the congratulation without the sentences about the prize.
 *
 * At most once per (winner, month), including retries of the listener and a repeated event: a `notifications_log` row of
 * type `league_month_won` (key `month:<id>`) is written BEFORE the Telegram call, under a short lock, as
 * {@see ReferralMilestoneSender}. A 403 deletes the row and closes the DMs of the learner (`dm_blocked_at`); a definite
 * refusal by the Bot API deletes it too, so a retry may deliver. Nothing is sent to a deleted or blocked learner or one whose
 * DM is closed. A failure of one winner does not stop the others: it is rethrown after the last one (the retry skips
 * those already messaged).
 */
final class MonthWinnerSender
{
    public const NOTIFICATION_TYPE = 'league_month_won';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly MonthlyRatingService $rating,
        private readonly DmLearnerResolver $dm,
    ) {}

    /**
     * @param  list<array{learner_id: int, place: int, points: int}>  $winners
     * @return array{sent: int, skipped: int, forbidden: int}
     */
    public function sendAll(int $monthId, array $winners): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'forbidden' => 0];

        /** @var LeagueMonth|null $month */
        $month = LeagueMonth::query()->find($monthId);
        if ($month === null) {
            return $result;
        }

        $failure = null;
        foreach ($winners as $winner) {
            try {
                $result[$this->send($month, $winner['learner_id'], $winner['place'], $winner['points'])]++;
            } catch (TelegramApiException $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    /** @return 'sent'|'forbidden'|'skipped' */
    public function send(LeagueMonth $month, int $learnerId, int $place, int $points): string
    {
        /** @var Learner|null $learner */
        $learner = Learner::query()->find($learnerId);
        if ($learner === null || $learner->blocked_at !== null || $learner->dm_blocked_at !== null) {
            return 'skipped';
        }

        return Cache::lock('league-month-won:dm:'.$learner->id.':'.$month->id, 60)->block(10, function () use ($learner, $month, $place, $points): string {
            $key = 'month:'.$month->id;

            $alreadySent = NotificationLog::query()
                ->where('learner_id', $learner->id)
                ->where('type', self::NOTIFICATION_TYPE)
                ->where('payload->key', $key)
                ->exists();
            if ($alreadySent) {
                return 'skipped';
            }

            $text = $this->text($learner, $month, $place, $points);

            $log = NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => self::NOTIFICATION_TYPE,
                'payload' => ['key' => $key, 'place' => $place],
                'sent_at' => now(),
            ]);

            try {
                $this->telegram->sendMessage($learner->telegram_user_id, $text);
            } catch (TelegramForbiddenException $e) {
                $log->delete();
                $this->dm->markBlocked($learner->telegram_user_id);
                Log::warning('telegram.league_month_won_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

                return 'forbidden';
            } catch (TelegramApiException $e) {
                // A transport failure may still have delivered (the marker stays); a definite refusal did not.
                if ($e->isApiResponse()) {
                    $log->delete();
                }

                throw $e;
            }

            return 'sent';
        });
    }

    private function text(Learner $learner, LeagueMonth $month, int $place, int $points): string
    {
        $locale = $this->locales->forLearner($learner);

        $prize = '';
        foreach ($this->rating->prizes($month) as $candidate) {
            if ($candidate->place === $place) {
                $prize = $candidate->title($locale);
                break;
            }
        }

        $replace = [
            'place' => $place,
            'month' => trans('leagues.month.label', [
                'name' => trans('leagues.month.names.'.$month->starts_on->month, [], $locale),
                'year' => $month->starts_on->year,
            ], $locale),
            'points' => trans_choice('leagues.month.points', $points, ['count' => $points], $locale),
            'prize' => $prize,
        ];

        return trans($prize === '' ? 'leagues.month.won_plain' : 'leagues.month.won', $replace, $locale);
    }
}
