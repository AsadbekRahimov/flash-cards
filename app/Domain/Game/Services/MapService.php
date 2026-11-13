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
use Illuminate\Support\Facades\DB;

/**
 * The map of the game "Caravan" (docs/18 §12.0, §12.2.3, §12.8; docs/04 A.18). It is DERIVED from the course, there is no table of
 * cities and stops: a city is a module (`stages`), a stop is a lesson with active words, the names of the cities are static
 * ({@see CityNames}), the titles are the ones of the cards ({@see DeckTitles}, the language of the learner).
 *
 *   - a stop is OPEN exactly when the learner has a `word_repetitions` row of an active word of the lesson (the lesson has been
 *     started in the cards; a `new` row counts). It is not stored, the counts of the learner's word rows
 *     per lesson ({@see CourseCatalogService::lessonsStarted()}) beside the cached tree of the course;
 *   - stars, runs, the score and the defeated boss are the learner's `game_stop_progress` / `game_city_progress` rows (one statement each);
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

        // Read the profile and limits together so both blocks show the same balance, including pending refunds.
        $limits = $this->limits->state($learner, $this->entitlements->for($learner, $now), $now);

        return new GameMapView(
            course: ['slug' => $course->slug, 'title' => $this->catalog->title($course, $locale)],
            cities: $this->cities($course, $learner, $locale, $limits->bossStages),
            daily: $day['daily'],
            me: [
                'stops_cleared' => $limits->stopsCleared,
                'cities_cleared' => $limits->citiesCleared,
                'coins' => $limits->coins,
                'tickets' => $limits->tickets,
            ],
            friends: $this->friends($learner, $course),
            activeRun: $day['active_run'],
            classCaravan: $this->classCaravans->activeCardFor($learner, $now),
            limits: $limits->toArray(),
        );
    }

    /**
     * The cities with their stops and bosses: the cached tree of the course (the lessons with active words, titles of the learner's
     * locale) plus statements by the learner's own rows - the word rows counted per lesson ({@see CourseCatalogService::lessonsStarted()})
     * and the stop / city progress ({@see progress()}). The statements do not grow with the lessons of the course.
     *
     * @return list<array{stage_id: int, number: int, city: string, title: string|null, unlocked: bool, stops: list<array{lesson_id: int, number: int, title: string|null, unlocked: bool, stars: int, best_score: int, runs: int}>, boss: array{state: string, defeated_at: string|null, introduced: int, required: int, cleared_stops: int, required_stops: int}}>
     */
    private function cities(Course $course, Learner $learner, string $locale, ?int $bossStages): array
    {
        // The stops are the lessons of the tree with active words (a lesson without any is not a stop, and a module of such lessons only is no city).
        $tree = array_values(array_filter(
            $this->catalog->tree($course, $locale),
            static fn (array $row): bool => $row['total'] > 0,
        ));
        if ($tree === []) {
            return [];
        }

        $started = $this->catalog->lessonsStarted($course, $learner);
        [$stops, $defeated] = $this->progress($learner, $tree);

        /** @var array<int, array{stage_id: int, number: int, title: string|null, defeated_at: string|null, stops: list<array{lesson_id: int, number: int, title: string|null, unlocked: bool, stars: int, best_score: int, runs: int}>, introduced: int}> $stages */
        $stages = [];
        foreach ($tree as $row) {
            $stageId = $row['stage_id'];
            $stages[$stageId] ??= [
                'stage_id' => $stageId,
                'number' => $row['stage_number'],
                'title' => $row['stage_title'],
                'defeated_at' => $defeated[$stageId] ?? null,
                'stops' => [],
                'introduced' => 0,
            ];

            $opened = $started[$row['lesson_id']]['started'] ?? 0;
            $stages[$stageId]['introduced'] += $opened;
            $stages[$stageId]['stops'][] = [
                'lesson_id' => $row['lesson_id'],
                'number' => $row['lesson_number'],
                'title' => $row['lesson_title'],
                'unlocked' => $opened > 0,
                'stars' => $stops[$row['lesson_id']]['stars'] ?? 0,
                'best_score' => $stops[$row['lesson_id']]['best_score'] ?? 0,
                'runs' => $stops[$row['lesson_id']]['runs'] ?? 0,
            ];
        }

        $cities = [];
        foreach ($stages as $stage) {
            $cleared = count(array_filter($stage['stops'], static fn (array $stop): bool => $stop['stars'] >= 1));
            $readiness = BossReadiness::of($stage['introduced'], count($stage['stops']), $cleared);

            $state = match (true) {
                $stage['defeated_at'] !== null => 'defeated',
                $bossStages !== null && $stage['number'] > $bossStages => 'locked_by_plan',
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
     * The learner's own game rows over the stops and the cities of the tree: two statements by `learner_id` and the ids of the tree
     * (the unique `(learner_id, lesson_id)` / `(learner_id, stage_id)` indexes), so they follow the learner and the course, not the
     * size of the course's words.
     *
     * @param  list<array{stage_id: int, lesson_id: int}>  $tree
     * @return array{0: array<int, array{stars: int, best_score: int, runs: int}>, 1: array<int, string>} stop progress by lesson id, ISO `boss_defeated_at` by stage id
     */
    private function progress(Learner $learner, array $tree): array
    {
        $stops = [];
        $rows = DB::table('game_stop_progress')
            ->where('learner_id', $learner->id)
            ->whereIn('lesson_id', array_column($tree, 'lesson_id'))
            ->get(['lesson_id', 'stars', 'best_score', 'runs_count']);
        foreach ($rows as $row) {
            $stops[(int) $row->lesson_id] = ['stars' => (int) $row->stars, 'best_score' => (int) $row->best_score, 'runs' => (int) $row->runs_count];
        }

        $defeated = [];
        $rows = DB::table('game_city_progress')
            ->where('learner_id', $learner->id)
            ->whereIn('stage_id', array_values(array_unique(array_column($tree, 'stage_id'))))
            ->whereNotNull('boss_defeated_at')
            ->get(['stage_id', 'boss_defeated_at']);
        foreach ($rows as $row) {
            $defeated[(int) $row->stage_id] = CarbonImmutable::parse((string) $row->boss_defeated_at, 'UTC')->toIso8601ZuluString('millisecond');
        }

        return [$stops, $defeated];
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

        $names = DB::table('learners')->whereNull('deleted_at')->whereIn('id', array_column($onCourse, 'learner_id'))->pluck('first_name', 'id');

        $friends = [];
        foreach ($onCourse as $friend) {
            // a friend who deleted their profile (until the purge too) is not listed
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
