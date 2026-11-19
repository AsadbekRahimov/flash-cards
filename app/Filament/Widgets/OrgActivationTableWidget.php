<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Domain\Organizations\Services\OrgActivationStats;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminDates;
use App\Filament\Resources\Support\AdminLabels;
use Carbon\CarbonImmutable;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\Paginator;

/**
 * «Центры: путь до первого результата» (audit 2026-11-18 §4): per center the time from its creation to the first group link,
 * the first assignment and the first assignment a learner completed, with the medians in the description; the centers still
 * waiting for a completed assignment come first, the oldest on top. Admin only: platform-wide data.
 *
 * A widget rather than columns of the organization list: the list would run the three `MIN` subqueries on every page and sort,
 * the widget reads one cached array ({@see OrgActivationStats}, `admin:widget:org_activation:v1:all`) and pages it in memory —
 * no query on a warm open, whatever the page (docs/20 §1.11).
 */
class OrgActivationTableWidget extends TableWidget
{
    use RequiresActiveAdmin;

    public const PAGE_SIZES = [10, 25];

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    /** After the money widgets (Revenue 9, Webhook errors and the Pro funnel 10), so they stay together; distinct from ExerciseMix (12). */
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('admin.widgets.org_activation.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            // A table widget pages in the simple mode (no COUNT): the page and one row more, which tells the paginator there is a next one.
            ->records(function (int|string $page, int|string $recordsPerPage): Paginator {
                [$page, $perPage] = [AdminDashboardStats::pageNumber((int) $page), AdminDashboardStats::pageSize((int) $recordsPerPage, self::PAGE_SIZES)];
                $rows = array_slice(app(OrgActivationStats::class)->figures()['rows'], ($page - 1) * $perPage, $perPage + 1);

                return new Paginator(array_column($rows, null, 'id'), $perPage, $page);
            })
            ->description(fn (): string => self::summary(app(OrgActivationStats::class)->figures()))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('admin.widgets.org_activation.name'))->weight('bold'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.widgets.org_activation.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('organizations.status', $state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.widgets.org_activation.created'))
                    ->formatStateUsing(fn (string $state): string => AdminDates::date(CarbonImmutable::parse($state, 'UTC')))
                    ->description(fn (array $record): string => __('admin.widgets.org_activation.age', ['duration' => self::duration((int) $record['age'])])),
                ...array_map(
                    fn (string $step): Tables\Columns\TextColumn => Tables\Columns\TextColumn::make($step)
                        ->label(__('admin.widgets.org_activation.steps.'.$step))
                        ->state(fn (array $record): ?int => $record[$step] === null ? null : (int) $record[$step])
                        ->formatStateUsing(fn (?int $state): string => self::duration($state))
                        ->placeholder(__('admin.widgets.org_activation.not_yet'))
                        ->color(fn (array $record): ?string => $step === 'completed' && $record['completed'] === null ? 'warning' : null),
                    OrgActivationStats::STEPS,
                ),
            ])
            ->emptyStateHeading(__('admin.widgets.org_activation.empty'))
            ->paginated(self::PAGE_SIZES)
            ->enforcePaginationPageOptions();
    }

    /**
     * "Медиана до первого выполненного задания: 3 дн 4 ч (12 из 40) · …. Ещё без выполненного задания: 28."
     *
     * @param  array{centers: int, waiting: int, steps: array<string, array{reached: int, median: int|null}>}  $figures
     */
    public static function summary(array $figures): string
    {
        $parts = [];
        foreach (OrgActivationStats::STEPS as $step) {
            $parts[] = __('admin.widgets.org_activation.median', [
                'step' => __('admin.widgets.org_activation.steps.'.$step),
                'duration' => self::duration($figures['steps'][$step]['median']),
                'reached' => $figures['steps'][$step]['reached'],
                'centers' => $figures['centers'],
            ]);
        }

        return implode(' · ', $parts).'. '.__('admin.widgets.org_activation.waiting', ['count' => $figures['waiting']]);
    }

    /** Whole seconds as "45 мин", "5 ч 20 мин", "3 дн 4 ч"; a dash for none. */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $days = intdiv($hours, 24);

        return match (true) {
            $hours < 1 => __('admin.widgets.org_activation.minutes', ['minutes' => $minutes]),
            $days < 1 => __('admin.widgets.org_activation.hours', ['hours' => $hours, 'minutes' => $minutes % 60]),
            default => __('admin.widgets.org_activation.days', ['days' => $days, 'hours' => $hours % 24]),
        };
    }
}
