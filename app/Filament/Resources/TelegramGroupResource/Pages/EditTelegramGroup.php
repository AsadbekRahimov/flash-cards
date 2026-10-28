<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramGroupResource\Pages;

use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\TelegramGroupResource;
use Filament\Resources\Pages\EditRecord;

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

        if (is_string($locale) && app(LocaleResolver::class)->isSupported($locale)) {
            $meta['locale'] = $locale;
        }

        $data['meta'] = $meta;

        return $data;
    }
}
