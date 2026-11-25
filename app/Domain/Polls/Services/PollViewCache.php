<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Operations\Support\PureCache;
use App\Domain\Polls\DTO\PollTapResult;
use App\Domain\Telegram\Handlers\PollCallbackHandler;
use App\Domain\Telegram\Services\PollMessenger;
use App\Models\Poll;
use App\Models\PollOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a learner sees of a poll — the question, the options and the answer rules — for the edit of the message after a tap
 * ({@see PollCallbackHandler}, docs/35 O5). A tap costs only the statements of {@see PollVoteService::tap()} (1-5): the texts and
 * the keyboard come from here, not from two more reads of `polls` and `poll_options` per tap.
 *
 * Pure cache (docs/19 §3): key `polls:view:v2:<poll id>`, an array of scalars with the `version` it was read at (the poll's
 * `updated_at` as a Unix second), TTL {@see TTL}; a lost entry is read again (2 statements). After the launch none of it changes (the
 * form refuses, O6); a draft is edited by {@see PollEditor}, which moves `updated_at` and forgets the key after the commit.
 *
 * The version closes the race of a miss with an edit (review of releases 3-4): a tap that read the poll before the edit committed may
 * put the OLD view into the cache after the editor's `forget`. Every tap knows the version of the poll it was decided on
 * ({@see PollTapResult::$pollVersion}, read in the same statement as the vote), and an entry older than that is read again - so once
 * the edit is committed no tap is shown the old text. Left: two edits of a draft within the same second (one version) with a tap
 * between them - the administrator's own test message, until the TTL.
 */
final class PollViewCache
{
    public const VERSION = 'v2';

    public const TTL = 600;

    /**
     * The view of the poll at `$version` or later ({@see version()} of its `updated_at`; 0 = any cached one).
     *
     * @return array{id: int, version: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool,
     *               options: list<array{id: int, position: int, label: array<string, string>}>}|null null when the poll is gone
     */
    public function get(int $pollId, int $version = 0): ?array
    {
        $store = PureCache::store();
        $cached = $store->get(self::key($pollId));
        if (is_array($cached) && isset($cached['version']) && (int) $cached['version'] >= $version) {
            /** @var array{id: int, version: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool, options: list<array{id: int, position: int, label: array<string, string>}>} $cached */
            return $cached;
        }

        $view = $this->read($pollId);
        if ($view !== null) {
            $store->put(self::key($pollId), $view, self::TTL);
        }

        return $view;
    }

    /** The version of a poll for {@see get()}: its `updated_at` (a model value or the raw column) as a Unix second, 0 when unknown. */
    public static function version(mixed $updatedAt): int
    {
        if ($updatedAt instanceof \DateTimeInterface) {
            return $updatedAt->getTimestamp();
        }

        if (! is_string($updatedAt) || $updatedAt === '') {
            return 0;
        }

        try {
            return CarbonImmutable::parse($updatedAt, 'UTC')->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function forget(int $pollId): void
    {
        PureCache::store()->forget(self::key($pollId));
    }

    /**
     * The view as models for {@see PollMessenger} (never saved; nothing is read).
     *
     * @param  array{id: int, version?: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool, options: list<array{id: int, position: int, label: array<string, string>}>}  $view
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
     * @return array{id: int, version: int, question: array<string, string>, allows_multiple: bool, max_choices: int|null, allow_change: bool,
     *               options: list<array{id: int, position: int, label: array<string, string>}>}|null
     */
    private function read(int $pollId): ?array
    {
        /** @var Poll|null $poll */
        $poll = Poll::query()->select(['id', 'question', 'allows_multiple', 'max_choices', 'allow_change', 'updated_at'])->find($pollId);
        if ($poll === null) {
            return null;
        }

        $options = [];
        foreach ($poll->options()->get(['id', 'position', 'label']) as $option) {
            $options[] = ['id' => (int) $option->id, 'position' => (int) $option->position, 'label' => self::texts($option->label)];
        }

        return [
            'id' => (int) $poll->id,
            'version' => self::version($poll->getRawOriginal('updated_at')),
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
