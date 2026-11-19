<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Learning\Support\LadderPolicy;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Hard words" of a class for the teacher (docs/06 §12.6): the words that several learners of the group have failed at
 * least `learning.leech.threshold` times (`word_repetitions.lapses`), most-shared first.
 *
 * An aggregate only: how many learners of the group count a word as hard, never who (docs/12 §4.4: a teacher does not see
 * a learner's own cards). Who is counted is decided by {@see OrgTenantScope} for the acting user, like the activity report:
 * the learners of the center's groups the user may see (every group of the center for an owner, the own groups for a
 * teacher), active in the group; a group outside the scope yields an empty report. The word it is confused with is the
 * first authored mark of the word (`words.meta.confusable_with`, as {@see LeechInsight} reads it first) - the pair
 * the learners' own wrong picks give is per learner and is not part of a class report.
 *
 * ONE grouped statement for the rows (group members -> `word_repetitions (learner_id, word_id)` -> words) and one count
 * for the size of the class; nothing per row. With the threshold 0 (leeches off) the report is empty. The same rows narrowed
 * to one lesson are the "material to repeat" of an assignment ({@see self::toRepeat()}, one statement).
 */
final class OrgHardWordsReport
{
    public const LIMIT = 100;

    public function __construct(
        private readonly OrgTenantScope $scope,
        private readonly LadderPolicy $policy,
    ) {}

    /**
     * The report of one group (or of every group the user sees, `$group = null`). With `$lessonId` only the active words of that
     * lesson are counted - the material of an assignment of the lesson; the class size stays the class's.
     *
     * @return array{rows: list<array{word_id: int, word: string, translation: string, learners: int, confused_with: string|null}>, class_size: int}
     */
    public function forGroup(User $user, Organization $organization, ?TelegramGroup $group, ?int $lessonId = null): array
    {
        $threshold = $this->policy->leechThreshold;
        if ($threshold <= 0) {
            return ['rows' => [], 'class_size' => 0];
        }

        $groups = $this->scope->groups($user, $organization);
        if ($group !== null) {
            $groups->whereKey($group->id);
        }
        $groupIds = $groups->select('telegram_groups.id');
        $learnerIds = $this->scope->learners($user, $organization)->select('learners.id');

        $classSize = DB::table('group_members as gm')
            ->whereIn('gm.telegram_group_id', (clone $groupIds)->toBase())
            ->where('gm.status', 'active')
            ->whereIn('gm.learner_id', (clone $learnerIds)->toBase())
            ->distinct()
            ->count('gm.learner_id');

        if ($classSize === 0) {
            return ['rows' => [], 'class_size' => 0];
        }

        $rows = $this->rowsQuery($organization, $groupIds->toBase(), $learnerIds->toBase(), $threshold, $lessonId)
            ->limit(self::LIMIT)
            ->get();

        return ['rows' => $this->mapRows($rows), 'class_size' => $classSize];
    }

    /**
     * "Material to repeat" of an assignment (the view page of `/org` assignments): the hardest active words of the lesson in
     * the group, only those that at least `$minLearners` learners of the group have failed `learning.leech.threshold` times
     * (`word_repetitions.lapses` - wrong answers counted by the learner's own reviews, over all time, not only since the
     * assignment was given). ONE statement, scoped like {@see self::forGroup()}: a group the user may not see is empty.
     *
     * @return list<array{word_id: int, word: string, translation: string, learners: int, confused_with: string|null}>
     */
    public function toRepeat(User $user, Organization $organization, TelegramGroup $group, int $lessonId, int $minLearners = 2, int $limit = 3): array
    {
        $threshold = $this->policy->leechThreshold;
        if ($threshold <= 0 || $limit <= 0) {
            return [];
        }

        $groupIds = $this->scope->groups($user, $organization)->whereKey($group->id)->select('telegram_groups.id');
        $learnerIds = $this->scope->learners($user, $organization)->select('learners.id');

        $rows = $this->rowsQuery($organization, $groupIds->toBase(), $learnerIds->toBase(), $threshold, $lessonId)
            ->havingRaw('COUNT(DISTINCT wr.learner_id) >= ?', [max(1, $minLearners)])
            ->limit($limit)
            ->get();

        return $this->mapRows($rows);
    }

    /**
     * The grouped statement of the rows (group members -> `word_repetitions (learner_id, word_id)` -> words), most-shared first.
     */
    private function rowsQuery(Organization $organization, QueryBuilder $groupIds, QueryBuilder $learnerIds, int $threshold, ?int $lessonId): QueryBuilder
    {
        return DB::table('group_members as gm')
            ->join('word_repetitions as wr', 'wr.learner_id', '=', 'gm.learner_id')
            ->join('words as w', 'w.id', '=', 'wr.word_id')
            ->leftJoin('word_translations as wt', function ($join) use ($organization): void {
                $join->on('wt.word_id', '=', 'w.id')->where('wt.locale', '=', $organization->locale);
            })
            ->whereIn('gm.telegram_group_id', $groupIds)
            ->where('gm.status', 'active')
            ->whereIn('gm.learner_id', $learnerIds)
            ->where('wr.lapses', '>=', $threshold)
            ->when($lessonId !== null, fn (QueryBuilder $q) => $q->where('w.lesson_id', $lessonId)->where('w.is_active', true))
            ->groupBy('w.id', 'wt.translation')
            ->orderByDesc('learners')
            ->orderBy('w.id')
            ->select('w.id', 'w.word', 'w.translation', 'w.meta', 'wt.translation as local_translation')
            ->selectRaw('COUNT(DISTINCT wr.learner_id) AS learners');
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{word_id: int, word: string, translation: string, learners: int, confused_with: string|null}>
     */
    private function mapRows(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $meta = is_string($row->meta) ? json_decode($row->meta, true) : $row->meta;
            $marks = is_array($meta) && is_array($meta['confusable_with'] ?? null) ? $meta['confusable_with'] : [];
            $mark = null;
            foreach ($marks as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '' && mb_strtolower(trim($candidate)) !== mb_strtolower(trim((string) $row->word))) {
                    $mark = trim($candidate);
                    break;
                }
            }

            $out[] = [
                'word_id' => (int) $row->id,
                'word' => (string) $row->word,
                'translation' => (string) ($row->local_translation ?? $row->translation),
                'learners' => (int) $row->learners,
                'confused_with' => $mark,
            ];
        }

        return $out;
    }
}
