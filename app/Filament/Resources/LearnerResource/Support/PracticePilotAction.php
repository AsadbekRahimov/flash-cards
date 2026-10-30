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
 * «Включить в пилот практики» / «Исключить из пилота» - the button on the learner's page and in the list (docs/16 §2.1, §3.2).
 * Admin only; the move and its audit record (`learner.practice_pilot_changed {from, to}`) are PracticePilotService's. The caption
 * and the icon follow the state of the learner, so one button does both.
 */
final class PracticePilotAction
{
    public static function make(): Action
    {
        return Action::make('practicePilot')
            ->label(fn (Learner $record): string => self::inPilot($record) ? __('admin.learners.actions.practice_pilot.exclude') : __('admin.learners.actions.practice_pilot.include'))
            ->icon(fn (Learner $record): string => self::inPilot($record) ? 'heroicon-o-user-minus' : 'heroicon-o-beaker')
            ->color(fn (Learner $record): string => self::inPilot($record) ? 'gray' : 'success')
            ->visible(fn (): bool => app(PanelDataScope::class)->isAdmin())
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(fn (Learner $record): string => self::inPilot($record) ? __('admin.learners.actions.practice_pilot.exclude_heading') : __('admin.learners.actions.practice_pilot.include_heading'))
            ->modalDescription(__('admin.learners.actions.practice_pilot.help'))
            ->action(function (Learner $record): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    return;
                }

                $pilot = app(PracticePilotService::class);
                $include = ! self::inPilot($record);
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
