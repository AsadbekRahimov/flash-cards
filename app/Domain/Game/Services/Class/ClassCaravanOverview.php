<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Class;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Game\DTO\ClassCaravanView;
use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunStatus;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Support\RunScoring;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\ClassCaravan;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * The reads of `GET /game/class` and `GET /game/class/{id}` (docs/18 §12.8, G2.9b): the caravans of the learner's classes as the Mini App
 * draws them. Who may see what is decided by {@see ClassCaravanService::visibleFor()} / `find()`; the table of contributions is its
 * `standings()` (cached 60 s while the caravan is active). The list is the short rows (a constant number of statements however many
 * caravans there are: the caravans, their groups and courses, the learner's boss runs); the table and the summary belong to one caravan.
 */
final class ClassCaravanOverview
{
    public function __construct(
        private readonly ClassCaravanService $caravans,
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * @return list<ClassCaravanView> the newest first, up to 10; without the table of contributions
     *
     * @throws GameException game_unavailable (403)
     */
    public function list(Learner $learner): array
    {
        $caravans = $this->caravans->visibleFor($learner);
        if ($caravans->isEmpty()) {
            return [];
        }

        $rows = array_values($caravans->all());
        (new EloquentCollection($rows))->load(['group:id,title', 'course']);
        $attempts = $this->attempts($learner, $rows);
        $locale = $this->locales->forLearner($learner);

        return array_map(fn (ClassCaravan $caravan): ClassCaravanView => $this->row($caravan, $attempts[ClassCaravanService::classBossKey($caravan, $learner)] ?? null, $locale), $rows);
    }

    /**
     * @throws GameException game_unavailable (403) | class_caravan_not_found (404)
     */
    public function show(Learner $learner, int $id): ClassCaravanView
    {
        $caravan = $this->caravans->find($learner, $id);
        $caravan->load(['group:id,title', 'course']);

        $standings = $this->caravans->standings($caravan);
        $members = [];
        $my = null;
        foreach ($standings as $row) {
            $isMe = $row['learner_id'] === $learner->id;
            $members[] = ['name' => $row['name'], 'stars' => $row['stars'], 'runs' => $row['runs'], 'best_score' => $row['best_score'], 'boss_won' => $row['boss_won'], 'is_me' => $isMe];
            if ($isMe) {
                $my = ['stars' => $row['stars'], 'runs' => $row['runs'], 'best_score' => $row['best_score'], 'boss_won' => $row['boss_won']];
            }
        }

        $attempts = $this->attempts($learner, [$caravan]);
        $row = $this->row($caravan, $attempts[ClassCaravanService::classBossKey($caravan, $learner)] ?? null, $this->locales->forLearner($learner));

        return new ClassCaravanView(
            id: $row->id,
            groupTitle: $row->groupTitle,
            course: $row->course,
            status: $row->status,
            startsAt: $row->startsAt,
            endsAt: $row->endsAt,
            starsTotal: $row->starsTotal,
            targetStars: $row->targetStars,
            runsTotal: $row->runsTotal,
            boss: $row->boss,
            members: $members,
            my: $my,
            summary: $caravan->status === ClassCaravanStatus::Finished ? [
                'target_reached' => $caravan->stars_total >= $caravan->target_stars,
                'boss_defeated' => $caravan->boss_defeated_at !== null,
                'finished_at' => $caravan->finished_at?->utc()->toIso8601ZuluString('millisecond'),
            ] : null,
        );
    }

    /** @param 'won'|'lost'|null $attempt */
    private function row(ClassCaravan $caravan, ?string $attempt, string $locale): ClassCaravanView
    {
        $course = $caravan->course; // never null (a NOT NULL foreign key)

        return new ClassCaravanView(
            id: $caravan->id,
            groupTitle: $caravan->group->title ?? '',
            course: $course === null ? ['slug' => '', 'title' => ''] : ['slug' => $course->slug, 'title' => $this->catalog->title($course, $locale)],
            status: $caravan->status === ClassCaravanStatus::Finished ? 'finished' : 'active',
            startsAt: $caravan->starts_at->utc()->toIso8601ZuluString('millisecond'),
            endsAt: $caravan->ends_at->utc()->toIso8601ZuluString('millisecond'),
            starsTotal: $caravan->stars_total,
            targetStars: $caravan->target_stars,
            runsTotal: $caravan->runs_total,
            boss: [
                'unlocked' => $caravan->boss_unlocked_at !== null,
                'defeated' => $caravan->boss_defeated_at !== null,
                'my_attempt' => $attempt,
            ],
        );
    }

    /**
     * The learner's fight with the boss of each caravan, from ONE statement over `game_runs.class_boss_key`: `won` / `lost` once the run is
     * finished (the same rule as `RunFinisher`: only a fight played to the end, `completed`, can be won - an abandoned or expired one is lost), nothing while it is still going on.
     *
     * @param  list<ClassCaravan>  $caravans
     * @return array<string, 'won'|'lost'>
     */
    private function attempts(Learner $learner, array $caravans): array
    {
        $keys = array_map(fn (ClassCaravan $caravan): string => ClassCaravanService::classBossKey($caravan, $learner), $caravans);
        $required = (float) config('game.boss.required_accuracy', 0.8);

        $attempts = [];
        $runs = DB::table('game_runs')
            ->where('learner_id', $learner->id)
            ->whereIn('class_boss_key', $keys)
            ->where('status', RunStatus::Finished->value)
            ->get(['class_boss_key', 'correct', 'total', 'finish_reason']);
        foreach ($runs as $run) {
            $attempts[(string) $run->class_boss_key] = $run->finish_reason === RunFinishReason::Completed->value && RunScoring::bossWon((int) $run->correct, (int) $run->total, $required) ? 'won' : 'lost';
        }

        return $attempts;
    }
}
