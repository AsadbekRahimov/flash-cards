<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Support\PollCallback;
use App\Domain\Telegram\Support\TelegramText;
use App\Models\Poll;
use App\Models\PollOption;

/**
 * What a learner sees of a poll (docs/35 §1.5 "Сообщение", docs/04 B.5b). O3 builds the first message of the broadcast here; O5 adds
 * the texts after a tap ("Спасибо", "Опрос завершён", …) on top of the same keyboard.
 *
 * After an answer the message is the question and "Спасибо" only — never the answer itself nor any totals (owner's decision,
 * docs/31 §5г), with «Изменить ответ» under it when the poll allows a change.
 *
 * - Caption (photo) or text: the question in the language of the delivery, no `parse_mode` (nothing the administrator types can
 *   become markup). When any option of that language is longer than {@see LONG_LABEL} characters, the options follow as a numbered
 *   list and the buttons say "1", "2", ….
 * - Buttons one per row, `pl1:<token>:<position>`; with several answers every option shows ☐/☑ and the last row is "Отправить"
 *   (`pl1:<token>:s`). Built only through {@see BotButtons}.
 */
final class PollMessenger
{
    /** Longer option labels make the buttons numbers and the options a list in the text. */
    public const LONG_LABEL = 24;

    /** Telegram's limits, in UTF-16 code units ({@see TelegramText}: an emoji is 2). */
    public const CAPTION_LIMIT = TelegramText::CAPTION_LIMIT;

    public const TEXT_LIMIT = TelegramText::MESSAGE_LIMIT;

    public const UNCHECKED = '☐';

    public const CHECKED = '☑';

    public function __construct(private readonly BotButtons $buttons) {}

    /** The language a delivery is sent in: the learner's when the poll has it, else Russian. */
    public static function locale(?string $locale): string
    {
        return in_array($locale, ['ru', 'uz'], true) ? $locale : 'ru';
    }

    /**
     * The text of the message (the caption when the poll has a picture).
     *
     * @param  iterable<PollOption>  $options  in the order of the buttons
     */
    public function text(Poll $poll, iterable $options, string $locale): string
    {
        $locale = self::locale($locale);
        $text = trim((string) ($poll->question[$locale] ?? $poll->question['ru'] ?? ''));

        if (! $this->numbered($options, $locale)) {
            return $text;
        }

        $lines = [];
        $n = 1;
        foreach ($options as $option) {
            $lines[] = $n++.'. '.$this->label($option, $locale);
        }

        return $text."\n\n".implode("\n", $lines);
    }

    /**
     * The keyboard of the options; `$selected` (poll_options ids) are ticked when the poll takes several answers.
     *
     * @param  iterable<PollOption>  $options
     * @param  list<int>  $selected
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function keyboard(Poll $poll, iterable $options, string $token, string $locale, int $chatId, array $selected = []): array
    {
        $locale = self::locale($locale);
        $numbered = $this->numbered($options, $locale);
        $rows = [];
        $n = 1;

        foreach ($options as $option) {
            $label = $numbered ? (string) $n : $this->label($option, $locale);
            if ($poll->allows_multiple) {
                $label = (in_array($option->id, $selected, true) ? self::CHECKED : self::UNCHECKED).' '.$label;
            }

            $rows[] = [$this->buttons->callback($label, PollCallback::data($token, $option->position))];
            $n++;
        }

        if ($poll->allows_multiple) {
            $rows[] = [$this->buttons->callback((string) __('polls.submit', [], $locale), PollCallback::data($token, PollCallback::SUBMIT))];
        }

        return $this->buttons->keyboard($chatId, $rows);
    }

    /**
     * The text after an answer: the question (without the list of options) and "Спасибо", within `$limit` UTF-16 units (the caption
     * of a photo, the text of a message) - the question is cut between graphemes when it would not fit.
     */
    public function answered(Poll $poll, string $locale, int $limit = self::CAPTION_LIMIT): string
    {
        $locale = self::locale($locale);
        $thanks = "\n\n".$this->notice('thanks', $locale);
        $question = trim((string) ($poll->question[$locale] ?? $poll->question['ru'] ?? ''));
        $room = max(0, $limit - TelegramText::length($thanks));

        return TelegramText::cut($question, $room).$thanks;
    }

    /**
     * The keyboard after an answer: «Изменить ответ» when the poll allows a change, else none (null removes the buttons).
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}|null
     */
    public function answeredKeyboard(string $token, string $locale, int $chatId, bool $allowChange): ?array
    {
        if (! $allowChange) {
            return null;
        }

        return $this->buttons->single($chatId, $this->buttons->callback($this->notice('change', $locale), PollCallback::data($token, PollCallback::CHANGE)));
    }

    /**
     * A short text of `lang/{ru,uz}/polls.php` in the language of the delivery: `thanks`, `change`, `closed`, `unavailable`, `empty`,
     * `limit` (`:max`), `locked`.
     *
     * @param  array<string, int|string>  $replace
     */
    public function notice(string $key, ?string $locale, array $replace = []): string
    {
        return (string) __('polls.'.$key, $replace, self::locale($locale));
    }

    /**
     * The longest text of the first message over both languages, against its limit (caption with a picture, text without): the
     * launcher refuses a poll that does not fit, instead of a broadcast where every send fails.
     *
     * @param  iterable<PollOption>  $options
     */
    public function fits(Poll $poll, iterable $options): bool
    {
        $limit = $poll->image_path !== null || $poll->image_file_id !== null ? self::CAPTION_LIMIT : self::TEXT_LIMIT;

        foreach (['ru', 'uz'] as $locale) {
            $text = $this->text($poll, $options, $locale);
            if ($text === '' || ! TelegramText::fits($text, $limit)) {
                return false;
            }
        }

        return true;
    }

    /** @param iterable<PollOption> $options */
    private function numbered(iterable $options, string $locale): bool
    {
        foreach ($options as $option) {
            if (mb_strlen($this->label($option, $locale)) > self::LONG_LABEL) {
                return true;
            }
        }

        return false;
    }

    private function label(PollOption $option, string $locale): string
    {
        return trim((string) ($option->label[$locale] ?? $option->label['ru'] ?? ''));
    }
}
