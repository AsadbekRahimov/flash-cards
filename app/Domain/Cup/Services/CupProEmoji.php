<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Operations\Support\PureCache;
use App\Models\Cup;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The emoji of Pro in the table of a cup (docs/31 §5а, docs/33 §11.1, K7): `participant id => emoji` of the participants with a live
 * Pro and a choice. A key of its own beside the table (as `league:emoji:v1:group:*` beside the standings of a league week): the table
 * is read by the bot too and never pays for the emoji.
 *
 * ONE statement on a miss, none from the cache. It is the rule of {@see ProEmojiService::visibleForMany()} - the set of the platform,
 * a live paid plan of one's own or of a seat ({@see PaidSubscriptionQuery}) - narrowed by the participants of the cup instead of a
 * list of learner ids: the table carries participant ids only (a learner id never leaves the server, docs/07 §10), so the ids would
 * cost a statement of their own. `CupProEmojiTest` holds the two in step.
 *
 * Pure cache (docs/19 §3): `cup:emoji:v1:{cup}`, an array of strings, TTL `cup.standings_ttl` (an emoji of a Pro that ran out shows at
 * most that long); dropped when somebody joins ({@see forget()}) and when a participant of an open cup changes his choice
 * ({@see learnerChanged()}).
 */
final class CupProEmoji
{
    private const PREFIX = 'cup:emoji:v1:';

    public function __construct(private readonly ProEmojiService $emoji) {}

    /** @return array<int, string> participant id => emoji */
    public function forCup(Cup $cup, ?CarbonImmutable $now = null): array
    {
        $key = self::key($cup->id);
        try {
            $cached = PureCache::store()->get($key);
            if (is_array($cached)) {
                return self::cast($cached);
            }
        } catch (Throwable $e) {
            report($e);
        }

        $map = $this->read($cup->id, $now ?? CarbonImmutable::now());

        try {
            PureCache::store()->put($key, $map, max(1, (int) config('cup.standings_ttl', 120)));
        } catch (Throwable $e) {
            report($e);
        }

        return $map;
    }

    /**
     * The emoji of many cups (`cups:refresh-cards`): the cached maps in one round trip, the missing ones in ONE statement.
     *
     * @param  list<int>  $cupIds
     * @return array<int, array<int, string>> cup id => (participant id => emoji)
     */
    public function forCups(array $cupIds, ?CarbonImmutable $now = null): array
    {
        $cupIds = array_values(array_unique($cupIds));
        if ($cupIds === []) {
            return [];
        }
        $maps = [];
        try {
            $cached = PureCache::store()->many(array_map(static fn (int $id): string => self::key($id), $cupIds));
            foreach ($cupIds as $id) {
                $hit = $cached[self::key($id)] ?? null;
                if (is_array($hit)) {
                    $maps[$id] = self::cast($hit);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        $missing = array_values(array_diff($cupIds, array_keys($maps)));
        if ($missing === []) {
            return $maps;
        }
        $fresh = $this->readMany($missing, $now ?? CarbonImmutable::now());
        $put = [];
        foreach ($fresh as $id => $map) {
            $maps[$id] = $put[self::key($id)] = $map;
        }
        try {
            PureCache::store()->putMany($put, max(1, (int) config('cup.standings_ttl', 120)));
        } catch (Throwable $e) {
            report($e);
        }

        return $maps;
    }

    /** Drops the emoji of a cup (a participant joined). Never throws (docs/19 §3 rule 5). */
    public function forget(int $cupId): void
    {
        PureCache::forgetMany([self::key($cupId)]);
    }

    /** The learner chose another emoji or took it back: the emoji of his OPEN cups are dropped (one indexed read, `learner_id`). */
    public function learnerChanged(Learner $learner): void
    {
        $cupIds = DB::table('cup_participants')
            ->join('cups', 'cups.id', '=', 'cup_participants.cup_id')
            ->where('cup_participants.learner_id', $learner->id)
            ->whereNotNull('cups.active_group_id')
            ->pluck('cups.id');
        $keys = [];
        foreach ($cupIds as $id) {
            $keys[] = self::key((int) $id);
        }
        if ($keys !== []) {
            PureCache::forgetMany($keys);
        }
    }

    public static function key(int $cupId): string
    {
        return self::PREFIX.$cupId;
    }

    /** @return array<int, string> */
    private function read(int $cupId, CarbonImmutable $now): array
    {
        return $this->readMany([$cupId], $now)[$cupId];
    }

    /**
     * @param  list<int>  $cupIds
     * @return array<int, array<int, string>> every asked cup id => its map
     */
    private function readMany(array $cupIds, CarbonImmutable $now): array
    {
        $maps = array_fill_keys($cupIds, []);
        $set = $this->emoji->set();
        if ($set === []) {
            return $maps;
        }
        $learners = fn (): Builder => DB::table('cup_participants as cpl')->whereIn('cpl.cup_id', $cupIds)->select('cpl.learner_id');

        $rows = DB::table('cup_participants as cp')
            ->join('learners', 'learners.id', '=', 'cp.learner_id')
            ->whereIn('cp.cup_id', $cupIds)
            ->whereIn('learners.pro_emoji', array_keys($set))
            // On `cp.learner_id`, not `learners.id`: so PostgreSQL walks the participants of the cup and reads each learner by its key
            // (0.5 ms, 200 buffers on the stand) instead of filtering every learner of the platform by the hashed subqueries (a
            // parallel scan of 150 000 learners, 34 ms) - the same set.
            ->where(fn (Builder $pro): Builder => $pro
                ->whereIn('cp.learner_id', PaidSubscriptionQuery::personalLearnerIds($now)->whereIn('subscriptions.subscriber_id', $learners()))
                ->orWhereIn('cp.learner_id', PaidSubscriptionQuery::sponsoredLearnerIds($now)->whereIn('om.learner_id', $learners())))
            ->get(['cp.cup_id', 'cp.id', 'learners.pro_emoji']);

        foreach ($rows as $row) {
            $maps[(int) $row->cup_id][(int) $row->id] = $set[(string) $row->pro_emoji];
        }

        return $maps;
    }

    /**
     * @param  array<mixed>  $cached
     * @return array<int, string>
     */
    private static function cast(array $cached): array
    {
        $map = [];
        foreach ($cached as $id => $value) {
            if (is_string($value)) {
                $map[(int) $id] = $value;
            }
        }

        return $map;
    }
}
