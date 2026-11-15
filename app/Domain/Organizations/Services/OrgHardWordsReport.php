<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Learning\Support\LadderPolicy;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
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
 * for the size of the class; nothing per row. With the threshold 0 (leeches off) the report is empty.
 */
final class OrgHardWordsReport
{
    public const LIMIT = 100;

    public function __construct(
        private readonly OrgTenantScope $scope,
        private readonly LadderPolicy $policy,
    ) {}

    /**
     * @return array{rows: list<array{word_id: int, word: string, translation: string, learners: int, confused_with: string|null}>, class_size: int}
     */
    public function forGroup(User $user, Organization $organization, ?TelegramGroup $group): array
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

        $rows = DB::table('group_members as gm')
            ->join('word_repetitions as wr', 'wr.learner_id', '=', 'gm.learner_id')
            ->join('words as w', 'w.id', '=', 'wr.word_id')
            ->leftJoin('word_translations as wt', function ($join) use ($organization): void {
                $join->on('wt.word_id', '=', 'w.id')->where('wt.locale', '=', $organization->locale);
            })
            ->whereIn('gm.telegram_group_id', $groupIds->toBase())
            ->where('gm.status', 'active')
            ->whereIn('gm.learner_id', $learnerIds->toBase())
            ->where('wr.lapses', '>=', $threshold)
            ->groupBy('w.id', 'wt.translation')
            ->orderByDesc('learners')
            ->orderBy('w.id')
            ->limit(self::LIMIT)
            ->select('w.id', 'w.word', 'w.translation', 'w.meta', 'wt.translation as local_translation')
            ->selectRaw('COUNT(DISTINCT wr.learner_id) AS learners')
            ->get();

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

        return ['rows' => $out, 'class_size' => $classSize];
    }
}
