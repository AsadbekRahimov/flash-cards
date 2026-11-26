<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\DTO\ReminderQuiz;
use App\Domain\Learners\DTO\ReminderQuizVerdict;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Telegram\Support\ReminderQuizCallback;
use App\Domain\Telegram\Support\TwaScreen;

/**
 * The texts and keyboards of the reminder question (docs/04 B.5a, docs/12 §7.5); formatting only, no I/O.
 *
 * Plain text, no `parse_mode`: the word and its translation are course content, and not switching markup on is safer than
 * escaping it. The options are one per row (translations are long); a button shows the first
 * {@see ReminderQuiz::BUTTON_WIDTH} characters, the verdict says the full text. The `/settings` footer stays in every
 * message — it is how the learner switches the reminders off instead of blocking the bot.
 */
final class ReminderQuizMessenger
{
    /** `from=` of the app button after the answer; the one of the question (and of the plain reminder) is {@see ReminderSender::FROM}. */
    public const FROM_ANSWERED = 'reminder_quiz';

    public function __construct(private readonly BotButtons $buttons) {}

    /**
     * @return array{text: string, reply_markup: array{inline_keyboard: list<list<array<string, mixed>>>}}
     */
    public function question(ReminderContent $content, ReminderQuiz $quiz, string $locale): array
    {
        $lead = match (true) {
            // The day after a lesson (docs/31 §5б, ReviewScoreHint): its words came up for review and earn points.
            $content->scoreHint => __('reminders.quiz.lead.score_due', [], $locale),
            $content->kind === ReminderKind::StreakAtRisk => trans_choice('reminders.quiz.lead.streak_at_risk', $content->streakCurrent, [], $locale),
            $content->kind === ReminderKind::Inactive => trans_choice('reminders.quiz.lead.inactive', $content->idleDays, [], $locale),
            $content->kind === ReminderKind::NewWords => __('reminders.quiz.lead.new_words', [], $locale),
            default => trans_choice('reminders.quiz.lead.review', $content->dueCount, [], $locale),
        };

        $question = $quiz->exercise === ExerciseType::Reverse
            ? __('reminders.quiz.question.reverse', ['translation' => $quiz->answer], $locale)
            : __('reminders.quiz.question.meaning', ['word' => $quiz->word], $locale);

        $keyboard = [];
        foreach ($quiz->options as $index => $option) {
            $keyboard[] = [['text' => ReminderQuiz::buttonLabel($option), 'callback_data' => ReminderQuizCallback::data($quiz->token, $index)]];
        }
        $keyboard[] = [$this->appButton(__('reminders.open_button', [], $locale), ReminderSender::FROM)];

        return [
            'text' => "{$lead}\n\n{$question}\n\n".__('reminders.quiz.footer', [], $locale),
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
    }

    /**
     * What the message turns into after a tap: the verdict with the right answer, how much waits in the app, one button.
     *
     * @return array{text: string, reply_markup: array{inline_keyboard: list<list<array<string, mixed>>>}}
     */
    public function verdict(ReminderQuizVerdict $verdict, string $locale): array
    {
        $line = __($verdict->correct ? 'reminders.quiz.correct' : 'reminders.quiz.wrong', ['word' => $verdict->word, 'answer' => $verdict->answer], $locale);
        $after = $verdict->dueCount > 0
            ? trans_choice('reminders.quiz.after_due', $verdict->dueCount, [], $locale)
            : __('reminders.quiz.after_none', [], $locale);

        return [
            'text' => "{$line}\n\n{$after}\n\n".__('reminders.quiz.footer', [], $locale),
            'reply_markup' => ['inline_keyboard' => [[$this->appButton(__('reminders.quiz.open_more', [], $locale), self::FROM_ANSWERED)]]],
        ];
    }

    /**
     * The keyboard of a question that is no longer there: the choice buttons go, the way into the app stays.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function expiredMarkup(string $locale): array
    {
        return ['inline_keyboard' => [[$this->appButton(__('reminders.open_button', [], $locale), ReminderSender::FROM)]]];
    }

    public function expiredAlert(string $locale): string
    {
        return __('reminders.quiz.expired', [], $locale);
    }

    /** @return array{text: string, web_app: array{url: string}} */
    private function appButton(string $text, string $from): array
    {
        return ['text' => $text, 'web_app' => ['url' => $this->buttons->twaUrl(TwaScreen::Today, query: ['from' => $from])]];
    }
}
