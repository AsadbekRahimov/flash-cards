<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Domain\Game\DTO\GameMapView;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\Runs\DailyCaravanService;
use App\Domain\Game\Support\BossReadiness;
use App\Domain\Game\Support\CityNames;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The map of the game "Caravan" (docs/18 §12.0, §12.2.3, §12.8; docs/04 A.18). It is DERIVED from the course, there is no table of
 * cities and stops: a city is a module (`stages`), a stop is a lesson with active words, the names of the cities are static
 * ({@see CityNames}), the titles are the ones of the cards ({@see DeckTitles}, the language of the learner).
 *
 *   - a stop is OPEN exactly when the learner has a `word_repetitions` row of an active word of the lesson (the lesson has been
 *     started in the cards; a `new` row counts). It is not stored, the same query that reads the course reads it;
 *   - stars, runs, the score and the defeated boss are the learner's `game_stop_progress` / `game_city_progress` rows, joined in
 *     that one query (they are 1:1 with the lesson / the module of the learner, so the join does not multiply anything);
 *   - the state of the boss only SAYS whether the fight may start (the fight is BossService's, the readiness is the one rule of
 *     {@see BossReadiness}): defeated / locked by the plan / ready / not ready;
 *   - the friends are the cached `game:friends` ({@see GameFriendsCache}), the rest of the learner's own state is one statement each.
 *
 * Nothing here writes. The statements of the map do not grow with the lessons and the friends (GameMapTest: the budget).
 */
final class MapService
{
    public function __construct(
        private readonly GameAccess $access,
        private readonly CourseCatalogService $catalog,
        private readonly DeckTitles $deckTitles,
        private readonly LocaleResolver $locales,
        private readonly EntitlementResolver $entitlements,
        private readonly LearnerStatsService $stats,
        private readonly DailyCaravanService $daily,
        private readonly GameLimits $limits,
        private readonly ClassCaravanService $classCaravans,
        private readonly FriendCosmetics $cosmetics,
    ) {}

    /**
     * @throws GameException game_unavailable (403) | course_not_found (404)
     */
    public function map(Learner $learner, string $courseSlug, ?CarbonImmutable $now = null): GameMapView
    {
        $now ??= CarbonImmutable::now();

        $this->access->assertAvailable($learner);

        $course = $this->catalog->findAvailable($courseSlug, $learner) ?? throw GameException::courseNotFound();
        $locale = $this->locales->forLearner($learner);

        // One definition of the day's caravan for the map and for `/today`: DailyCaravanService.
        $day = $this->daily->state($learner, $now, $this->stats->dueCount($learner, $now));

        return new GameMapView(
            course: ['slug' => $course->slug, 'title' => $this->catalog->title($course, $locale)],
            cities: $this->cities($course, $learner, $locale),
            daily: $day['daily'],
            me: $this->me($learner),
            friends: $this->friends($learner, $course),
            activeRun: $day['active_run'],
            classCaravan: $this->classCaravans->activeCardFor($learner, $now),
            limits: $this->limits->state($learner, $this->entitlements->for($learner, $now), $now)->toArray(),
        );
    }

    /**
     * The cities with their stops and bosses, from ONE statement: the lessons with active words, the learner's word rows counted per
     * lesson and their stop / city progress.
     *
     * @return list<array{stage_id: int, number: int, city: string, title: string|null, unlocked: bool, stops: list<array{lesson_id: int, number: int, title: string|null, unlocked: bool, stars: int, best_score: int, runs: int}>, boss: array{state: string, defeated_at: string|null, introduced: int, required: int, cleared_stops: int, required_stops: int}}>
     */
    private function cities(Course $course, Learner $learner, string $locale): array
    {
        $rows = DB::table('lessons')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->join('words', function (JoinClause $join): void {
                $join->on('words.lesson_id', '=', 'lessons.id')->where('words.is_active', '=', true);
            })
            ->leftJoin('word_repetitions', function (JoinClause $join) use ($learner): void {
                $join->on('word_repetitions.word_id', '=', 'words.id')->where('word_repetitions.learner_id', '=', $learner->id);
            })
            ->leftJoin('game_stop_progress', function (JoinClause $join) use ($learner): void {
                $join->on('game_stop_progress.lesson_id', '=', 'lessons.id')->where('game_stop_progress.learner_id', '=', $learner->id);
            })
            ->leftJoin('game_city_progress', function (JoinClause $join) use ($learner): void {
                $join->on('game_city_progress.stage_id', '=', 'stages.id')->where('game_city_progress.learner_id', '=', $learner->id);
            })
            ->where('stages.course_id', $course->id)
            ->groupBy(
                'stages.id', 'stages.number', 'stages.title', 'stages.title_uz',
                'lessons.id', 'lessons.number', 'lessons.title', 'lessons.title_uz',
                'game_stop_progress.stars', 'game_stop_progress.best_score', 'game_stop_progress.runs_count',
                'game_city_progress.boss_defeated_at',
            )
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->selectRaw(
                'stages.id as stage_id, stages.number as stage_number, stages.title as stage_title, stages.title_uz as stage_title_uz, '
                .'lessons.id as lesson_id, lessons.number as lesson_number, lessons.title as lesson_title, lessons.title_uz as lesson_title_uz, '
                .'COUNT(word_repetitions.id) as opened, '
                .'game_stop_progress.stars as stars, game_stop_progress.best_score as best_score, game_stop_progress.runs_count as runs, '
                .'game_city_progress.boss_defeated_at as boss_defeated_at',
            )
            ->get();

        $text = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        /** @var array<int, array{stage_id: int, number: int, title: string|null, defeated_at: string|null, stops: list<array{lesson_id: int, number: int, title: string|null, unlocked: bool, stars: int, best_score: int, runs: int}>, introduced: int}> $stages */
        $stages = [];
        foreach ($rows as $row) {
            $stageId = (int) $row->stage_id;
            $stages[$stageId] ??= [
                'stage_id' => $stageId,
                'number' => (int) $row->stage_number,
                'title' => $this->deckTitles->stageIn($course, $locale, $text($row->stage_title), $text($row->stage_title_uz)),
                'defeated_at' => $row->boss_defeated_at === null ? null : CarbonImmutable::parse((string) $row->boss_defeated_at, 'UTC')->toIso8601ZuluString('millisecond'),
                'stops' => [],
                'introduced' => 0,
            ];

            $opened = (int) $row->opened;
            $stages[$stageId]['introduced'] += $opened;
            $stages[$stageId]['stops'][] = [
                'lesson_id' => (int) $row->lesson_id,
                'number' => (int) $row->lesson_number,
                'title' => $this->deckTitles->lessonIn($course, $locale, (int) $row->lesson_number, $text($row->lesson_title), $text($row->lesson_title_uz)),
                'unlocked' => $opened > 0,
                'stars' => (int) $row->stars,
                'best_score' => (int) $row->best_score,
                'runs' => (int) $row->runs,
            ];
        }

        $planLocks = $this->bossPlanLock($learner);
        $cities = [];
        foreach ($stages as $stage) {
            $cleared = count(array_filter($stage['stops'], static fn (array $stop): bool => $stop['stars'] >= 1));
            $readiness = BossReadiness::of($stage['introduced'], count($stage['stops']), $cleared);

            $state = match (true) {
                $stage['defeated_at'] !== null => 'defeated',
                $planLocks($stage['number']) => 'locked_by_plan',
                $readiness->isReady() => 'ready',
                default => 'not_ready',
            };

            $cities[] = [
                'stage_id' => $stage['stage_id'],
                'number' => $stage['number'],
                'city' => CityNames::name($stage['number'], $locale),
                'title' => $stage['title'],
                'unlocked' => $stage['introduced'] > 0,
                'stops' => $stage['stops'],
                'boss' => [
                    'state' => $state,
                    'defeated_at' => $stage['defeated_at'],
                    'introduced' => $readiness->introduced,
                    'required' => $readiness->required,
                    'cleared_stops' => $readiness->clearedStops,
                    'required_stops' => $readiness->requiredStops,
                ],
            ];
        }

        return $cities;
    }

    /**
     * Whether the boss of the city `$number` is behind the plan for this learner (docs/18 §12.7.2): Free, past the first
     * `free_limits.boss_stages` cities, without the feature `game.all_bosses`. An empty limit is none; `0` is Pro only. The plan is
     * read when a city past the limit asks, and once.
     *
     * @return callable(int): bool
     */
    private function bossPlanLock(Learner $learner): callable
    {
        $limit = config('game.free_limits.boss_stages');
        $free = null;

        return function (int $number) use ($learner, $limit, &$free): bool {
            if ($limit === null || $limit === '' || $number <= (int) $limit) {
                return false;
            }
            $free ??= ! $this->entitlements->for($learner)->can('game.all_bosses');

            return $free;
        };
    }

    /**
     * The coins, the tickets and the totals of the learner in one statement (the rows are created lazily: a missing one is 0).
     *
     * @return array{stops_cleared: int, cities_cleared: int, coins: int, tickets: int}
     */
    private function me(Learner $learner): array
    {
        $row = DB::table('learners')
            ->leftJoin('game_wallets', 'game_wallets.learner_id', '=', 'learners.id')
            ->leftJoin('game_profiles', 'game_profiles.learner_id', '=', 'learners.id')
            ->where('learners.id', $learner->id)
            ->first([
                'game_wallets.balance as coins',
                'game_profiles.tickets as tickets',
                'game_profiles.stops_cleared as stops_cleared',
                'game_profiles.cities_cleared as cities_cleared',
            ]);

        return [
            'stops_cleared' => (int) ($row->stops_cleared ?? 0),
            'cities_cleared' => (int) ($row->cities_cleared ?? 0),
            'coins' => (int) ($row->coins ?? 0),
            'tickets' => (int) ($row->tickets ?? 0),
        ];
    }

    /**
     * The friends of the learner on the map of the course: the cached `game:friends` ({@see friendsOf()}) filtered to the course, the
     * most advanced first, up to `map.friends_max`; the names are read now (one statement), the cache keeps no names.
     *
     * @return list<array{friendship_id: int, name: string, city_number: int, stops_cleared: int, camel_asset: string|null}>
     */
    private function friends(Learner $learner, Course $course): array
    {
        /** @var list<array{friendship_id: int, learner_id: int, course_id: int, city_number: int, stops_cleared: int, camel_asset: string|null}> $all */
        $all = PureCache::store()->remember(
            GameFriendsCache::key($learner->id),
            GameFriendsCache::TTL_SECONDS,
            fn (): array => $this->friendsOf($learner),
        );

        $onCourse = array_values(array_filter($all, static fn (array $f): bool => $f['course_id'] === $course->id));
        usort($onCourse, static fn (array $a, array $b): int => [$b['stops_cleared'], $a['friendship_id']] <=> [$a['stops_cleared'], $b['friendship_id']]);
        $onCourse = array_slice($onCourse, 0, max(0, (int) config('game.map.friends_max')));
        if ($onCourse === []) {
            return [];
        }

        $names = DB::table('learners')->whereIn('id', array_column($onCourse, 'learner_id'))->pluck('first_name', 'id');

        $friends = [];
        foreach ($onCourse as $friend) {
            // a friend who deleted their profile is not listed
            if (! $names->has($friend['learner_id'])) {
                continue;
            }
            $friends[] = [
                'friendship_id' => $friend['friendship_id'],
                'name' => (string) $names->get($friend['learner_id']),
                'city_number' => $friend['city_number'],
                'stops_cleared' => $friend['stops_cleared'],
                'camel_asset' => $friend['camel_asset'],
            ];
        }

        return $friends;
    }

    /**
     * The value of `game:friends`: for every accepted friend and every course where they cleared a stop (>= 1 star) the furthest
     * city and the number of cleared stops, and the asset of the camel he wears (`null`: none; {@see FriendCosmetics}). Three
     * statements, whatever the friends are; arrays only (docs/19 §3).
     *
     * @return list<array{friendship_id: int, learner_id: int, course_id: int, city_number: int, stops_cleared: int, camel_asset: string|null}>
     */
    private function friendsOf(Learner $learner): array
    {
        $friendships = Friendship::query()
            ->where('status', FriendshipStatus::Accepted->value)
            ->where(fn ($q) => $q->where('learner_id', $learner->id)->orWhere('friend_id', $learner->id))
            ->get(['id', 'learner_id', 'friend_id']);

        /** @var array<int, int> $byFriend friend id => friendship id */
        $byFriend = [];
        foreach ($friendships as $friendship) {
            $byFriend[$friendship->otherId($learner->id)] = $friendship->id;
        }
        if ($byFriend === []) {
            return [];
        }

        $rows = DB::table('game_stop_progress')
            ->join('stages', 'stages.id', '=', 'game_stop_progress.stage_id')
            ->whereIn('game_stop_progress.learner_id', array_keys($byFriend))
            ->where('game_stop_progress.stars', '>=', 1)
            ->groupBy('game_stop_progress.learner_id', 'game_stop_progress.course_id')
            ->orderBy('game_stop_progress.learner_id')
            ->orderBy('game_stop_progress.course_id')
            ->selectRaw('game_stop_progress.learner_id as learner_id, game_stop_progress.course_id as course_id, MAX(stages.number) as city_number, COUNT(*) as stops_cleared')
            ->get();

        $camels = $this->cosmetics->camelAssets(array_values(array_unique(array_map(static fn (object $row): int => (int) $row->learner_id, $rows->all()))));

        $friends = [];
        foreach ($rows as $row) {
            $friends[] = [
                'friendship_id' => $byFriend[(int) $row->learner_id],
                'learner_id' => (int) $row->learner_id,
                'course_id' => (int) $row->course_id,
                'city_number' => (int) $row->city_number,
                'stops_cleared' => (int) $row->stops_cleared,
                'camel_asset' => $camels[(int) $row->learner_id] ?? null,
            ];
        }

        return $friends;
    }
}
