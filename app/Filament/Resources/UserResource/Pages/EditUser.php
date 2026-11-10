<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return __('admin.users.titles.edit');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->modalHeading(__('admin.users.actions.delete_heading'))];
    }

    /**
     * A Telegram id typed by a superadmin is a hand-typed id: it keeps the teacher's group commands working but proves nothing
     * (docs/07 §5, audit 2026-11-10 H1) — a change drops `telegram_verified_at` and the names the bot had recorded. Only the
     * person themselves, through `/start link_<token>`, verifies it again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $current = $this->getRecord()->getAttribute('telegram_user_id');
        $next = isset($data['telegram_user_id']) && $data['telegram_user_id'] !== '' ? (int) $data['telegram_user_id'] : null;

        if ($next !== ($current === null ? null : (int) $current)) {
            $data['telegram_verified_at'] = null;
            $data['telegram_username'] = null;
            $data['telegram_name'] = null;
        }

        return $data;
    }
}
