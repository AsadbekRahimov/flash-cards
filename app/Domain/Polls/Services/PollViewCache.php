<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Handlers\PollCallbackHandler;
use App\Domain\Telegram\Services\PollMessenger;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Support\Collection;

/**
 * What a learner sees of a poll — the question, the options and the answer rules — for the edit of the message after a tap
 * ({@see PollCallbackHandler}, docs/35 O5). A tap costs only the statements of {@see PollVoteService::tap()} (1-5): the texts and
 * the keyboard come from here, not from two more reads of `polls` and `poll_options` per tap.
 *
 * Pure cache (docs/19 §3): key `polls:view:v1:<poll id>`, an array of scalars, TTL {@see TTL}; a lost entry is read again (2
 * statements). After the launch none of it changes (the form refuses, O6); a draft is edited by {@see PollEditor}, which forgets the
 * key after the commit — the administrator's test message then shows the new text on the next tap.
 */
final class PollViewCache
{
    public const VERSION = 'v1';

    public const TTL = 600;

    /**
     * @return array{id: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool,
     *               options: list<array{id: int, position: int, label: array<string, string>}>}|null null when the poll is gone
     */
    public function get(int $pollId): ?array
    {
        /** @var array{id: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool, options: list<array{id: int, position: int, label: array<string, string>}>}|null */
        return PureCache::store()->remember(self::key($pollId), self::TTL, fn (): ?array => $this->read($pollId));
    }

    public function forget(int $pollId): void
    {
        PureCache::store()->forget(self::key($pollId));
    }

    /**
     * The view as models for {@see PollMessenger} (never saved; nothing is read).
     *
     * @param  array{id: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool, options: list<array{id: int, position: int, label: array<string, string>}>}  $view
     * @return array{0: Poll, 1: Collection<int, PollOption>}
     */
    public static function models(array $view): array
    {
        $poll = new Poll;
        $poll->forceFill([
            'id' => $view['id'],
            'question' => $view['question'],
            'allows_multiple' => $view['allows_multiple'],
            'max_choices' => $view['max_choices'],
            'allow_change' => $view['allow_change'],
        ]);

        $options = new Collection;
        foreach ($view['options'] as $row) {
            $option = new PollOption;
            $option->forceFill(['id' => $row['id'], 'poll_id' => $view['id'], 'position' => $row['position'], 'label' => $row['label']]);
            $options->push($option);
        }

        return [$poll, $options];
    }

    public static function key(int $pollId): string
    {
        return 'polls:view:'.self::VERSION.':'.$pollId;
    }

    /**
     * @return array{id: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool,
     *               options: list<array{id: int, position: int, label: array<string, string>}>}|null
     */
    private function read(int $pollId): ?array
    {
        /** @var Poll|null $poll */
        $poll = Poll::query()->select(['id', 'question', 'allows_multiple', 'max_choices', 'allow_change'])->find($pollId);
        if ($poll === null) {
            return null;
        }

        $options = [];
        foreach ($poll->options()->get(['id', 'position', 'label']) as $option) {
            $options[] = ['id' => (int) $option->id, 'position' => (int) $option->position, 'label' => self::texts($option->label)];
        }

        return [
            'id' => (int) $poll->id,
            'question' => self::texts($poll->question),
            'allows_multiple' => (bool) $poll->allows_multiple,
            'max_choices' => $poll->max_choices,
            'allow_change' => (bool) $poll->allow_change,
            'options' => $options,
        ];
    }

    /**
     * @param  array<array-key, mixed>|null  $texts
     * @return array<string, string>
     */
    private static function texts(?array $texts): array
    {
        $out = [];
        foreach (['ru', 'uz'] as $locale) {
            $out[$locale] = is_string($texts[$locale] ?? null) ? $texts[$locale] : '';
        }

        return $out;
    }
}
