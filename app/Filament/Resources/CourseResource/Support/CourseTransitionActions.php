<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Support;

use App\Domain\Content\Exceptions\CoursePublishException;
use App\Domain\Content\Services\CoursePublisher;
use App\Models\Course;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Status buttons of a course (table row and edit page). Every move goes through
 * {@see CoursePublisher}, which owns the rules, the translation threshold and the audit log.
 */
final class CourseTransitionActions
{
    private const LABELS = [
        CoursePublisher::REVIEW => ['Send to review', 'heroicon-o-eye', 'warning'],
        CoursePublisher::PUBLISHED => ['Publish', 'heroicon-o-rocket-launch', 'success'],
        CoursePublisher::ARCHIVED => ['Archive', 'heroicon-o-archive-box', 'danger'],
        CoursePublisher::DRAFT => ['Back to draft', 'heroicon-o-pencil-square', 'gray'],
    ];

    /** @return list<Action> */
    public static function all(): array
    {
        return array_map(fn (string $to): Action => self::make($to), array_keys(self::LABELS));
    }

    public static function make(string $to): Action
    {
        [$label, $icon, $color] = self::LABELS[$to];

        return Action::make('transition_'.$to)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Course $record): bool => in_array($to, app(CoursePublisher::class)->allowedTransitions($record), true))
            ->authorize('update')
            ->requiresConfirmation()
            ->modalDescription($to === CoursePublisher::PUBLISHED
                ? 'The course needs titles in every language, at least one lesson and translations (uz + ru) for almost all words.'
                : null)
            ->action(function (Course $record) use ($to): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(CoursePublisher::class)->transition($record, $to, $user, request()->ip());
                } catch (CoursePublishException $e) {
                    Notification::make()->title('Status not changed')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title("Course is now {$to}")->success()->send();
            });
    }
}
