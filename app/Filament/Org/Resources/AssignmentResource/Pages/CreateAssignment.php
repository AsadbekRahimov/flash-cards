<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\AssignmentResource\Pages;

use App\Domain\Organizations\Exceptions\AssignmentException;
use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Filament\Org\Resources\AssignmentResource;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAssignment extends CreateRecord
{
    protected static string $resource = AssignmentResource::class;

    /**
     * Creation (course / lesson checks, default title, event, announcement) is AssignmentService's
     * job. The submitted group id is re-checked against the user's own scope: the option list is
     * only a convenience, the request body is not trusted.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $tenant instanceof Organization || ! $user instanceof User) {
            abort(404);
        }

        $group = app(OrgTenantScope::class)->groups($user, $tenant)->whereKey((int) $data['telegram_group_id'])->first();
        $lesson = Lesson::query()->find((int) $data['lesson_id']);

        try {
            if ($group === null) {
                throw AssignmentException::foreignGroup((int) $data['telegram_group_id'], $tenant->id);
            }

            if ($lesson === null) {
                throw AssignmentException::lessonNotInCourse((int) $data['lesson_id'], $group->id);
            }

            return app(AssignmentService::class)->create(
                $tenant,
                $group,
                $lesson,
                CarbonImmutable::parse((string) $data['due_at']),
                filled($data['title'] ?? null) ? (string) $data['title'] : null,
                (int) ($data['completion_threshold'] ?? 100),
                $user,
            );
        } catch (AssignmentException $e) {
            Notification::make()->title(__('org.assignments.errors.'.$e->errorCode))->danger()->send();

            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('org.assignments.notifications.created');
    }
}
