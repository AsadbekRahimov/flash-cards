<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\HubView;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Models\Battle;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The hub of the practice for a course / a lesson (docs/16 §7.1): the four modes with their state, the unfinished session and
 * the summary of the battles. A read: it changes nothing but the closing of an expired session it comes across.
 *
 * `state` of a mode: `available`, `not_enough` (with the reason of ModeAvailability), `disabled` (the switch is off: only the
 * CREATION is blocked), `pro_required` (a Free learner at `PRACTICE_PLAN=pro`: the entry is shown with a Pro chip and the
 * paywall opens on the tap). A learner without access (`off`, `not_in_pilot`) gets 403 `practice_unavailable`.
 */
final class PracticeHubService
{
    public function __construct(
        private readonly PracticeAccess $access,
        private readonly PracticeMaterialService $material,
        private readonly PracticeSessionService $sessions,
        private readonly CourseCatalogService $catalog,
        private readonly DeckTitles $deckTitles,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * @throws PracticeException practice_unavailable | course_not_found | lesson_not_found | lesson_not_in_course
     */
    public function hub(Learner $learner, string $courseSlug, ?int $lessonId, ?CarbonImmutable $now = null): HubView
    {
        $now ??= CarbonImmutable::now();

        $access = $this->access->state($learner, $now);
        if (! $access->showsEntries()) {
            throw PracticeException::unavailable();
        }

        $course = $this->material->course($courseSlug, $learner);
        $lesson = $lessonId === null ? null : $this->material->lesson($course, $lessonId, $learner);
        $locale = $this->locales->forLearner($learner);

        $availability = $access->isProRequired()
            ? []
            : $this->material->availability(new MaterialScope($course, $lesson), $learner, new LocaleSet([$locale]));

        $modes = [];
        foreach (PracticeMode::cases() as $mode) {
            $modes[] = $this->modeRow($mode, $access->isProRequired(), $availability[$mode->value] ?? null);
        }

        $active = $this->sessions->activeFor($learner, $now);

        return new HubView(
            course: ['slug' => $course->slug, 'title' => $this->catalog->title($course, $locale)],
            lesson: $lesson === null ? null : [
                'id' => $lesson->id,
                'stage' => $lesson->stage?->number,
                'number' => $lesson->number,
                'title' => $this->deckTitles->lesson($course, $learner, $lesson->number, $lesson->title),
            ],
            modes: $modes,
            activeSession: $active === null ? null : $this->sessions->activeSummary($active),
            battle: $this->battleSummary($learner, $now),
        );
    }

    /** @return array{mode: string, state: string, available: int, required: int, reason: string|null, estimate_seconds: int} */
    private function modeRow(PracticeMode $mode, bool $proRequired, ?ModeAvailability $availability): array
    {
        $required = $mode === PracticeMode::Pairs
            ? max(1, (int) config('practice.solo.pairs.min_per_round'))
            : max(1, (int) config('practice.solo.items.min'));

        $state = match (true) {
            $proRequired => 'pro_required',
            ! $mode->isEnabled() => 'disabled',
            $availability !== null && $availability->isAvailable => 'available',
            default => 'not_enough',
        };

        // A switched-off mode and the one behind the paywall show no numbers: nothing was counted for them.
        if ($availability !== null && ($state === 'available' || $state === 'not_enough')) {
            return [
                'mode' => $mode->value,
                'state' => $state,
                'available' => $availability->available,
                'required' => $availability->required,
                'reason' => $availability->reason,
                'estimate_seconds' => $mode->estimateSeconds(),
            ];
        }

        return [
            'mode' => $mode->value,
            'state' => $state,
            'available' => 0,
            'required' => $required,
            'reason' => null,
            'estimate_seconds' => $mode->estimateSeconds(),
        ];
    }

    /**
     * The battle block of the hub in ONE query on `battles`: my match that is not over (my own invitation that has not
     * expired, a room, a countdown, a game) and the invitations to me that have not expired.
     *
     * @return array{enabled: bool, active_battle_id: int|null, incoming_count: int}
     */
    private function battleSummary(Learner $learner, CarbonImmutable $now): array
    {
        $live = [BattleStatus::Room->value, BattleStatus::Countdown->value, BattleStatus::Playing->value];

        /** @var Collection<int, Battle> $rows */
        $rows = Battle::query()
            ->select(['id', 'challenger_id', 'opponent_id', 'status', 'invite_expires_at'])
            ->where(function (Builder $mine) use ($learner): void {
                $mine->where('challenger_id', $learner->id)->orWhere('opponent_id', $learner->id);
            })
            ->where(function (Builder $open) use ($live, $now): void {
                $open->whereIn('status', $live)
                    ->orWhere(fn (Builder $invited) => $invited->where('status', BattleStatus::Invited->value)->where('invite_expires_at', '>', $now));
            })
            ->orderByDesc('id')
            ->get();

        $activeId = null;
        $incoming = 0;
        foreach ($rows as $battle) {
            $invitedToMe = $battle->status === BattleStatus::Invited && $battle->opponent_id === $learner->id;
            if ($invitedToMe) {
                $incoming++;

                continue;
            }
            $activeId ??= $battle->id;
        }

        return ['enabled' => $this->access->battleEnabled(), 'active_battle_id' => $activeId, 'incoming_count' => $incoming];
    }
}
