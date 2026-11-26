<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramGroupResource\Pages;

use App\Domain\Cup\Services\CupPlatformAdmin;
use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\TelegramGroupResource;
use App\Models\TelegramGroup;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditTelegramGroup extends EditRecord
{
    protected static string $resource = TelegramGroupResource::class;

    public function getTitle(): string
    {
        return __('admin.telegram_groups.titles.edit');
    }

    /**
     * The bot language lives in `meta['locale']`. It is edited through a
     * dedicated select, so lift it out of the free-form meta key/value editor.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $data['locale'] = $meta['locale'] ?? null;
        unset($meta['locale']);
        // The flags of the cups are set by their own actions (audited); the key/value editor would turn them into strings.
        foreach (CupPlatformAdmin::GROUP_FLAGS as $flag) {
            unset($meta[$flag]);
        }
        $data['meta'] = $meta;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locale = $data['locale'] ?? null;
        unset($data['locale']);

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        unset($meta['locale']);
        foreach (CupPlatformAdmin::GROUP_FLAGS as $flag) {
            unset($meta[$flag]);
        }

        if (is_string($locale) && app(LocaleResolver::class)->isSupported($locale)) {
            $meta['locale'] = $locale;
        }

        $data['meta'] = $meta;

        return $data;
    }

    /**
     * The flags of the cups (`cups_blocked`, `cup_pilot`) are never the form's: they are taken from the row as it is NOW, under
     * `FOR UPDATE` - an action that set one while the form was open (it writes under the same lock) is not undone by saving the
     * form, and a key typed into the editor by hand cannot set one (or turn it into the string "1").
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TelegramGroup $record */
        return DB::transaction(function () use ($record, $data): TelegramGroup {
            /** @var TelegramGroup $current */
            $current = TelegramGroup::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            foreach (CupPlatformAdmin::GROUP_FLAGS as $flag) {
                unset($meta[$flag]);
                if (array_key_exists($flag, $current->meta ?? [])) {
                    $meta[$flag] = $current->meta[$flag];
                }
            }
            $data['meta'] = $meta;

            $record->update($data);

            return $record;
        });
    }
}
