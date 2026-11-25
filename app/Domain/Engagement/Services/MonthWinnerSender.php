<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Support\TwaScreen;
use App\Listeners\Engagement\SendMonthWinnerMessages;
use App\Models\LeagueMonth;
use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
 * those already messaged). A 429 is the exception: it stops the loop at once - the others would get it too.
 *
 * One button (docs/36 §3а, N4): "🏆 Rating of the month" -> `/league/month?from=month_won`.
 *
 * The message is a photo (docs/36 §2б, A5): the picture of {@see MonthWinnerCardBuilder} (place, month, the table of a v2 month - no
 * name) uploaded as a file (not by its URL: it does not depend on a public https address), the congratulation as its caption. When
 * there is no picture to send (the renderer failed, the cards are not a local file) the congratulation goes out as the text it was
 * before; the failure goes to `report()`. Ties on a prize place are separate winners: each gets their own message.
 */
final class MonthWinnerSender
{
    public const NOTIFICATION_TYPE = 'league_month_won';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly MonthlyRatingService $rating,
        private readonly DmReachability $reachability,
        private readonly BotButtons $buttons,
        private readonly MonthWinnerCardBuilder $cards,
    ) {}

    /**
     * @param  list<array{learner_id: int, place: int, points: int, category?: string}>  $winners  `category` - the table of a v2 month
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
                $category = isset($winner['category']) ? ScoreCategory::tryFrom($winner['category']) : null;
                $result[$this->send($month, $winner['learner_id'], $winner['place'], $winner['points'], $category)]++;
            } catch (TelegramRateLimitedException $e) {
                // Flood control: the next winners would get a 429 too. Out at once; the listener is put back for `retry_after`
                // and the retry skips those already messaged (docs/34 §4в п. 8).
                throw $e;
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
    public function send(LeagueMonth $month, int $learnerId, int $place, int $points, ?ScoreCategory $category = null): string
    {
        /** @var Learner|null $learner */
        $learner = Learner::query()->find($learnerId);
        if ($learner === null || $learner->blocked_at !== null || $learner->dm_blocked_at !== null) {
            return 'skipped';
        }

        return Cache::lock('league-month-won:dm:'.$learner->id.':'.$month->id, 60)->block(10, function () use ($learner, $month, $place, $points, $category): string {
            $key = 'month:'.$month->id;

            $alreadySent = NotificationLog::query()
                ->where('learner_id', $learner->id)
                ->where('type', self::NOTIFICATION_TYPE)
                ->where('payload->key', $key)
                ->exists();
            if ($alreadySent) {
                return 'skipped';
            }

            $locale = $this->locales->forLearner($learner);
            $text = $this->text($learner, $month, $place, $points, $category);
            $photo = $this->photo($month, $place, $points, $category, $locale);
            $keyboard = $this->buttons->single($learner->telegram_user_id, $this->buttons->open(
                __('buttons.month_rating', [], $locale),
                TwaScreen::LeagueMonth,
                query: ['from' => 'month_won'],
            ));

            $log = NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => self::NOTIFICATION_TYPE,
                'payload' => ['key' => $key, 'place' => $place, 'photo' => $photo !== null],
                'sent_at' => now(),
            ]);

            try {
                if ($photo !== null) {
                    $this->telegram->sendPhotoMessage($learner->telegram_user_id, PhotoSource::file($photo), $text, $keyboard);
                } else {
                    $this->telegram->sendMessage($learner->telegram_user_id, $text, replyMarkup: $keyboard);
                }
            } catch (TelegramForbiddenException $e) {
                $log->delete();
                $this->reachability->markUnreachable($learner->telegram_user_id, $e->reason);
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

    /** The local JPEG of the picture, or null (reported) when there is none to upload: the congratulation goes out as text then. */
    private function photo(LeagueMonth $month, int $place, int $points, ?ScoreCategory $category, string $locale): ?string
    {
        try {
            $path = $this->cards->render($month, $place, $points, $category, $locale);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            // A disk that is not local (`achievements.disk` on S3): `path()` is a key there, nothing to upload.
            report(new RuntimeException("The card of the month winner is not a local file: {$path}"));

            return null;
        }

        return $path;
    }

    private function text(Learner $learner, LeagueMonth $month, int $place, int $points, ?ScoreCategory $category = null): string
    {
        $locale = $this->locales->forLearner($learner);

        $prize = '';
        foreach ($this->rating->prizes($month, $category) as $candidate) {
            if ($candidate->place === $place) {
                $prize = $candidate->title($locale);
                break;
            }
        }

        $replace = [
            'place' => $place,
            'month' => MonthWinnerCardBuilder::monthName($month, $locale),
            'points' => trans_choice('leagues.month.points', $points, ['count' => $points], $locale),
            'prize' => $prize,
        ];

        return trans($prize === '' ? 'leagues.month.won_plain' : 'leagues.month.won', $replace, $locale);
    }
}
