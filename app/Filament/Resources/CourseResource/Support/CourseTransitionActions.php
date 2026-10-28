<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Support;

use App\Domain\Content\Exceptions\CoursePublishException;
use App\Domain\Content\Services\CoursePublisher;
use App\Filament\Resources\Support\AdminLabels;
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
    /** Target status => [icon, color]; the caption is `admin.courses.actions.<status>`. */
    private const STYLES = [
        CoursePublisher::REVIEW => ['heroicon-o-eye', 'warning'],
        CoursePublisher::PUBLISHED => ['heroicon-o-rocket-launch', 'success'],
        CoursePublisher::ARCHIVED => ['heroicon-o-archive-box', 'danger'],
        CoursePublisher::DRAFT => ['heroicon-o-pencil-square', 'gray'],
    ];

    /** @return list<Action> */
    public static function all(): array
    {
        return array_map(fn (string $to): Action => self::make($to), array_keys(self::STYLES));
    }

    public static function make(string $to): Action
    {
        [$icon, $color] = self::STYLES[$to];

        return Action::make('transition_'.$to)
            ->label(__('admin.courses.actions.'.$to))
            ->icon($icon)
            ->color($color)
            ->visible(fn (Course $record): bool => in_array($to, app(CoursePublisher::class)->allowedTransitions($record), true))
            ->authorize('update')
            ->requiresConfirmation()
            ->when($to === CoursePublisher::PUBLISHED, fn (Action $action): Action => $action->modalDescription(__('admin.courses.actions.publish_help')))
            ->action(function (Course $record) use ($to): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(CoursePublisher::class)->transition($record, $to, $user, request()->ip());
                } catch (CoursePublishException $e) {
                    Notification::make()->title(__('admin.courses.notifications.failed'))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title(__('admin.courses.notifications.moved', ['status' => AdminLabels::value('courses.status', $to)]))->success()->send();
            });
    }
}
