<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\Support;

use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Practice\Services\PracticePilotService;
use App\Models\Learner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * «Включить в пилот практики» / «Исключить из пилота» - the buttons on the learner's page and in the list (docs/16 §2.1, §3.2).
 * Admin only; the move and its audit record (`learner.practice_pilot_changed {from, to}`) are PracticePilotService's.
 *
 * There are TWO actions, one for each direction, and a button shows only the one that fits the learner NOW. The direction is the
 * action's own (`practicePilotInclude` / `practicePilotExclude`), never worked out when the click is handled: a page that was
 * opened before another admin changed the learner would otherwise turn the move round, and a confirmed "Включить" could take a
 * learner OUT of the pilot. A stale button now finds the learner in the state it asked for and says so ("ничего не изменено").
 */
final class PracticePilotAction
{
    /** The learner enters the pilot. */
    public static function include(): Action
    {
        return self::make(include: true);
    }

    /** The learner leaves the pilot. */
    public static function exclude(): Action
    {
        return self::make(include: false);
    }

    private static function make(bool $include): Action
    {
        $texts = $include ? 'include' : 'exclude';

        return Action::make($include ? 'practicePilotInclude' : 'practicePilotExclude')
            ->label(__("admin.learners.actions.practice_pilot.{$texts}"))
            ->icon($include ? 'heroicon-o-beaker' : 'heroicon-o-user-minus')
            ->color($include ? 'success' : 'gray')
            ->visible(fn (Learner $record): bool => app(PanelDataScope::class)->isAdmin() && self::inPilot($record) !== $include)
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(__("admin.learners.actions.practice_pilot.{$texts}_heading"))
            ->modalDescription(__('admin.learners.actions.practice_pilot.help'))
            ->action(function (Learner $record) use ($include): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    return;
                }

                $pilot = app(PracticePilotService::class);
                $changed = $include
                    ? $pilot->include($record, $actor, request()->ip())
                    : $pilot->exclude($record, $actor, request()->ip());

                $title = match (true) {
                    ! $changed => 'unchanged',
                    $include => 'included',
                    default => 'excluded',
                };

                Notification::make()->title(__('admin.learners.actions.practice_pilot.'.$title))->success()->send();
            });
    }

    private static function inPilot(Learner $learner): bool
    {
        return $learner->practice_pilot_at !== null;
    }
}
