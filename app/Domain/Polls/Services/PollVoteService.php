<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\DTO\PollTapResult;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Enums\PollTapStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A tap on a poll button `pl1:<token>:<n|s|c>` (docs/35 §1.5 "Голос", docs/04 B.5b, O4). The callback data is untrusted and
 * asserts nothing: the token only FINDS the delivery, the right to answer comes from Telegram's `from.id` (the webhook is
 * authenticated by its secret header), which must be the delivery's `telegram_user_id`. So a guessed or leaked token gives nobody
 * else's vote; an unknown token, a foreign tap, a missing option or a message that never went out all answer `Unavailable` alike,
 * after the same one statement (no oracle). The token is 12 characters of [A-Za-z0-9] from a CSPRNG (≈ 2^71); the format is checked
 * before any statement.
 *
 * One transaction, the delivery row locked (`FOR UPDATE OF d` - the poll row is read, never locked, so taps of different learners
 * never wait for each other): two simultaneous taps of one learner are serialized and the second sees the first's result -
 * one vote, counters that agree.
 *
 * Statements (a test pins them): the locking SELECT of the delivery with its poll and options - 1 (all an unavailable / closed
 * tap costs); + the recorded votes when there is an answer (a separate statement AFTER the lock: see {@see decide()}); the first
 * answer of one choice - 3 (SELECT, INSERT vote, UPDATE delivery); the same answer again - 2; a change - 5 (+ votes, DELETE); a
 * toggle of several answers - 2 (3 after an answer); "Отправить" - up to 5.
 *
 * Rules:
 *  - open = poll `sending` / `sent` (a `draft` only for a test delivery: the administrator checks the path before the launch), not
 *    past `closes_at`; else `Closed`. The delivery must have gone out (`sending`, `sent`, `unknown`);
 *  - one answer: an option tap records it (`Voted`); the same option again is `Repeated` (nothing written: it heals a lost edit);
 *    another option replaces the answer when `allow_change` (`changed_count + 1`), else `Locked`;
 *  - several answers: an option tap toggles it in `draft` (from the recorded answer when there is no draft) within `max_choices`;
 *    `s` records the draft (`EmptySubmit` when empty, `Repeated` when it equals the answer);
 *  - `c` ("Изменить ответ") with `allow_change`: `Changing` - several answers put the recorded answer into `draft`;
 *  - `answered_at` is the first answer, `poll_votes.created_at` the current one. Nothing goes to the learning statistics, points or
 *    streak; the event `poll.answered` is O7's (from `Voted` + `changed`).
 */
final class PollVoteService
{
    private const TOKEN = '~^[A-Za-z0-9]{12}$~D';

    private const ACTION = '~^([0-9]|s|c)$~D';

    private const CHANGED_COUNT_MAX = 32767;

    public function tap(string $token, int $fromId, string $action, CarbonImmutable $now): PollTapResult
    {
        if ($fromId <= 0 || preg_match(self::TOKEN, $token) !== 1 || preg_match(self::ACTION, $action) !== 1) {
            return PollTapResult::unavailable();
        }

        return DB::transaction(fn (): PollTapResult => $this->decide($token, $fromId, $action, $now->utc()));
    }

    private function decide(string $token, int $fromId, string $action, CarbonImmutable $now): PollTapResult
    {
        $lock = DB::getDriverName() === 'pgsql' ? ' FOR UPDATE OF d' : '';
        $row = DB::selectOne(
            'SELECT d.id, d.poll_id, d.telegram_user_id, d.status, d.locale, d.is_test, d.answered_at, d.draft, '
            .'p.status AS poll_status, p.closes_at, p.allows_multiple, p.max_choices, p.allow_change, '
            ."(SELECT string_agg(CAST(o.id AS text) || ':' || CAST(o.position AS text), ',') FROM poll_options o WHERE o.poll_id = d.poll_id) AS options "
            .'FROM poll_deliveries d JOIN polls p ON p.id = d.poll_id WHERE d.token = ?'.$lock,
            [$token],
        );

        if ($row === null || (int) $row->telegram_user_id !== $fromId
            || ! in_array($row->status, ['sending', 'sent', 'unknown'], true)
        ) {
            return PollTapResult::unavailable();
        }

        $deliveryId = (int) $row->id;
        $pollId = (int) $row->poll_id;
        $isTest = (bool) $row->is_test;
        $multiple = (bool) $row->allows_multiple;
        $allowChange = (bool) $row->allow_change;
        $answered = $row->answered_at !== null;
        // The recorded answer is read only now, in its own statement: this one starts after the row lock was granted, so it sees
        // what a parallel tap committed while this one waited. (A subquery of the locking SELECT would read the snapshot taken
        // BEFORE the wait - the delivery row itself is re-read by PostgreSQL, the votes are not.) No answer, no votes: both are
        // written in one transaction.
        $current = [];
        $result = fn (PollTapStatus $status, array $selected, bool $changed = false): PollTapResult => new PollTapResult(
            $status, self::sorted($selected), $allowChange, $multiple, $changed, $pollId, $deliveryId, (string) $row->locale, $isTest,
        );

        $pollStatus = PollStatus::tryFrom((string) $row->poll_status);
        $open = $pollStatus === PollStatus::Sending || $pollStatus === PollStatus::Sent || ($pollStatus === PollStatus::Draft && $isTest);
        if (! $open || ($row->closes_at !== null && CarbonImmutable::parse((string) $row->closes_at, 'UTC')->lessThanOrEqualTo($now))) {
            return $result(PollTapStatus::Closed, []);
        }

        if ($answered) {
            $current = self::ids(DB::table('poll_votes')->where('poll_delivery_id', $deliveryId)->pluck('poll_option_id')->all());
        }

        // position => option id
        $options = [];
        foreach (array_filter(explode(',', (string) $row->options)) as $pair) {
            [$id, $position] = array_map('intval', explode(':', $pair, 2) + [1 => -1]);
            $options[$position] = $id;
        }

        if ($action === 'c') {
            if ($answered && ! $allowChange) {
                return $result(PollTapStatus::Locked, $current);
            }

            if (! $multiple) {
                return $result(PollTapStatus::Changing, $current);
            }

            $draft = $row->draft === null ? null : self::ids($row->draft);
            $selection = $answered ? $current : ($draft ?? []);
            if ($draft === null || self::sorted($draft) !== self::sorted($selection)) {
                $this->writeDraft($deliveryId, $selection);
            }

            return $result(PollTapStatus::Changing, $selection);
        }

        if ($action === 's') {
            if (! $multiple) {
                return PollTapResult::unavailable();
            }

            $draft = $row->draft === null ? null : self::ids($row->draft);
            if ($answered && ! $allowChange) {
                return $result($draft === null || self::sorted($draft) === $current ? PollTapStatus::Repeated : PollTapStatus::Locked, $current);
            }

            $selection = $draft ?? ($answered ? $current : []);
            if ($selection === []) {
                return $result(PollTapStatus::EmptySubmit, []);
            }

            if ($answered && self::sorted($selection) === $current) {
                if ($draft !== null) {
                    $this->writeDraft($deliveryId, null);
                }

                return $result(PollTapStatus::Repeated, $current);
            }

            $this->record($pollId, $deliveryId, $selection, $current, $answered, $now);

            return $result(PollTapStatus::Voted, $selection, $answered);
        }

        $optionId = $options[(int) $action] ?? null;
        if ($optionId === null) {
            return PollTapResult::unavailable();
        }

        if (! $multiple) {
            if ($answered && $current === [$optionId]) {
                return $result(PollTapStatus::Repeated, $current);
            }

            if ($answered && ! $allowChange) {
                return $result(PollTapStatus::Locked, $current);
            }

            $this->record($pollId, $deliveryId, [$optionId], $current, $answered, $now);

            return $result(PollTapStatus::Voted, [$optionId], $answered);
        }

        if ($answered && ! $allowChange) {
            return $result(PollTapStatus::Locked, $current);
        }

        $draft = $row->draft === null ? ($answered ? $current : []) : self::ids($row->draft);
        if (in_array($optionId, $draft, true)) {
            $draft = array_values(array_diff($draft, [$optionId]));
        } else {
            $max = $row->max_choices === null ? count($options) : (int) $row->max_choices;
            if (count($draft) >= $max) {
                return $result(PollTapStatus::LimitReached, $draft);
            }
            $draft[] = $optionId;
        }

        $this->writeDraft($deliveryId, self::sorted($draft));

        return $result(PollTapStatus::Toggled, $draft);
    }

    /**
     * Replaces the recorded answer by `$selection`: only the difference is deleted / inserted (the unique
     * `(poll_delivery_id, poll_option_id)` is never hit under the row lock), then the delivery is stamped and its draft cleared.
     *
     * @param  list<int>  $selection
     * @param  list<int>  $current
     */
    private function record(int $pollId, int $deliveryId, array $selection, array $current, bool $answered, CarbonImmutable $now): void
    {
        $remove = array_values(array_diff($current, $selection));
        $add = array_values(array_diff($selection, $current));
        $stamp = $now->format('Y-m-d H:i:s');

        if ($remove !== []) {
            DB::table('poll_votes')->where('poll_delivery_id', $deliveryId)->whereIn('poll_option_id', $remove)->delete();
        }

        if ($add !== []) {
            DB::table('poll_votes')->insert(array_map(static fn (int $optionId): array => [
                'poll_id' => $pollId,
                'poll_delivery_id' => $deliveryId,
                'poll_option_id' => $optionId,
                'created_at' => $stamp,
            ], $add));
        }

        DB::update(
            'UPDATE poll_deliveries SET answered_at = COALESCE(answered_at, ?), draft = NULL, '
            .'changed_count = CASE WHEN ? = 1 AND changed_count < ? THEN changed_count + 1 ELSE changed_count END WHERE id = ?',
            [$stamp, $answered ? 1 : 0, self::CHANGED_COUNT_MAX, $deliveryId],
        );
    }

    /** @param list<int>|null $draft */
    private function writeDraft(int $deliveryId, ?array $draft): void
    {
        DB::table('poll_deliveries')->where('id', $deliveryId)->update([
            'draft' => $draft === null ? null : json_encode(self::sorted($draft), JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<int> ascending, unique, positive - from a list, a JSON list (`draft`) */
    private static function ids(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = match (true) {
            is_array($value) => $value,
            is_string($value) => (array) json_decode($value, true),
            default => [],
        };

        $ids = [];
        foreach ($items as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return self::sorted($ids);
    }

    /**
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
