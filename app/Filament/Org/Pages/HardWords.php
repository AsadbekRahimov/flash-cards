<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use App\Domain\Organizations\Services\OrgHardWordsReport;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * "Hard words" of a group (docs/06 §12.6) for both staff roles: the words that several learners of the class keep failing,
 * with how many of them and the word they are confused with. Thin: the numbers and the scoping are {@see OrgHardWordsReport}
 * (an owner sees every group of the center, a teacher their own); this page only collects the group filter.
 *
 * @property-read Schema $form
 */
class HardWords extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 65;

    protected string $view = 'filament.org.pages.hard-words';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('org.nav.hard_words');
    }

    public function getTitle(): string
    {
        return __('org.nav.hard_words');
    }

    public function mount(): void
    {
        $this->form->fill(['group' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('group')
                    ->label(__('org.reports.filters.group'))
                    ->placeholder(__('org.reports.filters.all_groups'))
                    ->options(fn (): array => $this->groupOptions())
                    ->live(),
            ]);
    }

    /** @return array{rows: list<array{word_id: int, word: string, translation: string, learners: int, confused_with: string|null}>, class_size: int} */
    public function report(): array
    {
        $group = $this->selectedGroup();

        // A group the user may not see is an empty report, not "all groups".
        if (filled($this->data['group'] ?? null) && $group === null) {
            return ['rows' => [], 'class_size' => 0];
        }

        return app(OrgHardWordsReport::class)->forGroup($this->user(), $this->tenant(), $group);
    }

    private function selectedGroup(): ?TelegramGroup
    {
        $groupId = $this->data['group'] ?? null;

        if (! filled($groupId) || ! is_numeric($groupId)) {
            return null;
        }

        return app(OrgTenantScope::class)->groups($this->user(), $this->tenant())
            ->whereKey((int) $groupId)
            ->first();
    }

    /** @return array<int, string> */
    private function groupOptions(): array
    {
        /** @var array<int, string> $options */
        $options = app(OrgTenantScope::class)->groups($this->user(), $this->tenant())
            ->orderBy('telegram_groups.title')
            ->pluck('telegram_groups.title', 'telegram_groups.id')
            ->all();

        return $options;
    }

    private function tenant(): Organization
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organization, 404);

        return $tenant;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
