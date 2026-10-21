<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Learning\DTO\SchedulerMetrics;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Services\SchedulerMetricsService;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * "SM-2 vs FSRS" over the last 28 days (docs/13 §8.3, docs/06 §9.6). Admin only: platform-wide numbers.
 */
class SchedulerComparisonWidget extends StatsOverviewWidget
{
    use RequiresActiveAdmin;

    public const CACHE_KEY = 'admin:scheduler-comparison';

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 7;

    protected ?string $heading = 'SM-2 vs FSRS, last 28 days';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        /** @var array<string, SchedulerMetrics> $metrics */
        $metrics = Cache::remember(self::CACHE_KEY, 120, fn (): array => app(SchedulerMetricsService::class)->compare());

        $sm2 = $metrics[SchedulerKind::Sm2->value];
        $fsrs = $metrics[SchedulerKind::Fsrs->value];

        return [
            Stat::make('Pass rate of due cards', self::pair(self::percent($sm2->passRate()), self::percent($fsrs->passRate())))
                ->description(self::pair("{$sm2->duePasses} / {$sm2->dueReviews}", "{$fsrs->duePasses} / {$fsrs->dueReviews}").' due review cards')
                ->icon('heroicon-o-check-circle')
                ->color('primary'),
            Stat::make('Reviews per graduated word', self::pair(self::ratio($sm2->reviewsPerGraduatedWord()), self::ratio($fsrs->reviewsPerGraduatedWord())))
                ->description(self::pair("{$sm2->reviews} / {$sm2->graduatedWords}", "{$fsrs->reviews} / {$fsrs->graduatedWords}").' reviews / words')
                ->icon('heroicon-o-arrow-path')
                ->color('primary'),
            Stat::make('D7 retention by bucket', self::pair(self::percent($sm2->retentionD7()), self::percent($fsrs->retentionD7())))
                ->description(self::pair("{$sm2->retainedD7} / {$sm2->cohortLearners}", "{$fsrs->retainedD7} / {$fsrs->cohortLearners}").' learners signed up 28–7 days ago')
                ->icon('heroicon-o-user-group')
                ->color('primary'),
        ];
    }

    private static function pair(string $sm2, string $fsrs): string
    {
        return "SM-2 {$sm2} · FSRS {$fsrs}";
    }

    private static function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 1).'%';
    }

    private static function ratio(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }
}
