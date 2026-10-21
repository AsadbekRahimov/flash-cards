<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use App\Domain\Organizations\DTO\DateRange;
use App\Domain\Organizations\DTO\GroupActivityRow;
use App\Domain\Organizations\DTO\LearnerActivityRow;
use App\Domain\Organizations\Exceptions\ReportRangeException;
use App\Domain\Organizations\Services\OrgActivityReport;
use App\Domain\Organizations\Services\OrgActivityReportCsv;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Activity report of the center (docs/13 §6.4) for both staff roles: an owner sees the whole
 * organization, a teacher only their groups — the scoping is done by {@see OrgTenantScope} inside
 * {@see OrgActivityReport}, this page only collects the filters (group, 7 / 30 days / own period of
 * at most {@see DateRange::MAX_DAYS} days) and shows / exports the result. Aggregates only.
 *
 * @property-read Schema $form
 */
class Reports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.org.pages.reports';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('org.nav.reports');
    }

    public function getTitle(): string
    {
        return __('org.nav.reports');
    }

    public function mount(): void
    {
        $tenant = $this->tenant();
        $custom = DateRange::lastDays(30, $tenant);

        $this->form->fill([
            'group' => null,
            'period' => '30',
            'from' => $custom->from,
            'to' => $custom->to,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(['default' => 1, 'md' => 4])
            ->components([
                Select::make('group')
                    ->label(__('org.reports.filters.group'))
                    ->placeholder(__('org.reports.filters.all_groups'))
                    ->options(fn (): array => $this->groupOptions())
                    ->live(),
                Select::make('period')
                    ->label(__('org.reports.filters.period'))
                    ->options([
                        '7' => __('org.reports.filters.period_7'),
                        '30' => __('org.reports.filters.period_30'),
                        'custom' => __('org.reports.filters.period_custom'),
                    ])
                    ->default('30')
                    ->selectablePlaceholder(false)
                    ->live(),
                DatePicker::make('from')
                    ->label(__('org.reports.filters.from'))
                    ->native(false)
                    ->maxDate(fn (): string => CarbonImmutable::now()->setTimezone($this->tenant()->timezone)->toDateString())
                    ->visible(fn (Get $get): bool => $get('period') === 'custom')
                    ->live(),
                DatePicker::make('to')
                    ->label(__('org.reports.filters.to'))
                    ->native(false)
                    ->maxDate(fn (): string => CarbonImmutable::now()->setTimezone($this->tenant()->timezone)->toDateString())
                    ->visible(fn (Get $get): bool => $get('period') === 'custom')
                    ->live(),
            ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('csvLearners')
                ->label(__('org.reports.actions.csv_learners'))
                ->icon('heroicon-o-arrow-down-tray')
                ->disabled(fn (): bool => $this->rangeError() !== null)
                ->action(fn (): StreamedResponse => $this->downloadLearners()),
            Action::make('csvGroups')
                ->label(__('org.reports.actions.csv_groups'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->disabled(fn (): bool => $this->rangeError() !== null)
                ->action(fn (): StreamedResponse => $this->downloadGroups()),
        ];
    }

    /** Selected period; `null` when the custom dates are invalid (see {@see rangeError()}). */
    public function range(): ?DateRange
    {
        $tenant = $this->tenant();
        $period = (string) ($this->data['period'] ?? '30');

        if ($period === '7' || $period === '30') {
            return DateRange::lastDays((int) $period, $tenant);
        }

        try {
            return DateRange::between((string) ($this->data['from'] ?? ''), (string) ($this->data['to'] ?? ''));
        } catch (ReportRangeException) {
            return null;
        }
    }

    public function rangeError(): ?string
    {
        if ($this->range() !== null) {
            return null;
        }

        try {
            DateRange::between((string) ($this->data['from'] ?? ''), (string) ($this->data['to'] ?? ''));
        } catch (ReportRangeException $e) {
            return __('org.reports.errors.'.$e->errorCode, ['days' => DateRange::MAX_DAYS]);
        }

        return null;
    }

    /** @return list<LearnerActivityRow> */
    public function learnerRows(): array
    {
        $range = $this->range();
        $group = $this->selectedGroup();

        if ($range === null || ($this->hasGroupFilter() && $group === null)) {
            return [];
        }

        return app(OrgActivityReport::class)->perLearner($this->user(), $this->tenant(), $group, $range);
    }

    /** @return list<GroupActivityRow> */
    public function groupRows(): array
    {
        $range = $this->range();
        $group = $this->selectedGroup();

        if ($range === null || ($this->hasGroupFilter() && $group === null)) {
            return [];
        }

        $rows = app(OrgActivityReport::class)->perGroup($this->user(), $this->tenant(), $range);

        return $group === null ? $rows : array_values(array_filter($rows, fn (GroupActivityRow $row): bool => $row->groupId === $group->id));
    }

    public function formatSeen(?CarbonImmutable $seenAt): string
    {
        return $seenAt === null ? __('org.reports.never') : $seenAt->setTimezone($this->tenant()->timezone)->format('d.m.Y H:i');
    }

    private function downloadLearners(): StreamedResponse
    {
        $range = $this->range() ?? DateRange::lastDays(30, $this->tenant());
        $rows = $this->learnerRows();
        $tenant = $this->tenant();
        $csv = app(OrgActivityReportCsv::class);

        return response()->streamDownload(function () use ($csv, $tenant, $rows): void {
            $out = fopen('php://output', 'wb');
            if ($out !== false) {
                $csv->writeLearners($out, $tenant, $rows);
                fclose($out);
            }
        }, $csv->filename('activity', $tenant, $range), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function downloadGroups(): StreamedResponse
    {
        $range = $this->range() ?? DateRange::lastDays(30, $this->tenant());
        $rows = $this->groupRows();
        $tenant = $this->tenant();
        $csv = app(OrgActivityReportCsv::class);

        return response()->streamDownload(function () use ($csv, $rows): void {
            $out = fopen('php://output', 'wb');
            if ($out !== false) {
                $csv->writeGroups($out, $rows);
                fclose($out);
            }
        }, $csv->filename('groups', $tenant, $range), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function hasGroupFilter(): bool
    {
        return filled($this->data['group'] ?? null);
    }

    /** The chosen group, only if the user may see it (otherwise `null` and the report is empty). */
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
