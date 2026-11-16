<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use App\Models\LeagueMonth;
use App\Models\Learner;
use Illuminate\Support\Facades\Lang;

/**
 * How the review pages of the monthly rating (docs/13 §7.6) word what the service gives them, from the rows already loaded:
 * the month's name, the anti-abuse `signals` of a candidate as short facts and coloured flags with their explanation.
 * Nothing here reads the database — a page row costs no query.
 */
final class LeagueMonthDisplay
{
    /** The colour of the badge of every status of a month. */
    private const STATUS_COLORS = ['open' => 'gray', 'closing' => 'info', 'pending_review' => 'warning', 'confirmed' => 'success'];

    private const FLAG_COLORS = [
        'low_accuracy' => 'warning',
        'high_accuracy' => 'warning',
        'too_fast' => 'danger',
        'young_account' => 'warning',
        'spiky' => 'danger',
        'capped_often' => 'warning',
    ];

    /**
     * Why the bot cannot reach a candidate: 'blocked' (blocked by an administrator), 'deleted' (soft-deleted or purged),
     * null when nothing stands in the way. From the loaded learner, no query.
     *
     * @return 'blocked'|'deleted'|null
     */
    public static function unreachable(?Learner $learner): ?string
    {
        if ($learner === null || $learner->trashed()) {
            return 'deleted';
        }

        return $learner->blocked_at !== null ? 'blocked' : null;
    }

    /** «ноябрь 2026» — Russian whatever the request's locale (the panel is Russian). */
    public static function month(LeagueMonth $month): string
    {
        return (string) Lang::get('leagues.month.label', [
            'name' => (string) Lang::get('leagues.month.names.'.$month->starts_on->month, [], 'ru'),
            'year' => $month->starts_on->year,
        ], 'ru');
    }

    public static function statusColor(string $status): string
    {
        return self::STATUS_COLORS[$status] ?? 'gray';
    }

    /**
     * The facts of `MonthlyRatingService::signals()` as short lines («Точность: 87,3 %»); a fact that is not there is skipped.
     *
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    public static function facts(array $signals): array
    {
        $facts = [];
        $line = static fn (string $key, string $value): string => (string) __("admin.league_month.signals.{$key}", ['value' => $value]);

        if (is_numeric($signals['accuracy'] ?? null)) {
            $facts[] = $line('accuracy', AdminNumbers::decimal((float) $signals['accuracy'] * 100, 1, trimZeros: true));
        }
        if (is_numeric($signals['avg_ms_per_review'] ?? null)) {
            $facts[] = $line('avg_time', AdminNumbers::decimal((float) $signals['avg_ms_per_review'] / 1000, 1, trimZeros: true));
        }
        if (is_numeric($signals['capped_days'] ?? null)) {
            $facts[] = $line('capped_days', (string) (int) $signals['capped_days']);
        }
        if (is_numeric($signals['max_day_points'] ?? null)) {
            $facts[] = $line('max_day', (string) (int) $signals['max_day_points']);
        }
        if (is_numeric($signals['spike_ratio'] ?? null)) {
            $facts[] = $line('spike', AdminNumbers::decimal((float) $signals['spike_ratio'], 1, trimZeros: true));
        }
        if (is_numeric($signals['account_age_days'] ?? null)) {
            $facts[] = $line('account_age', (string) (int) $signals['account_age_days']);
        }
        if (is_numeric($signals['overall_rank'] ?? null)) {
            $facts[] = $line('overall_rank', (string) (int) $signals['overall_rank']);
        }

        return $facts;
    }

    /**
     * The flags of a candidate: caption, explanation (with the configured threshold) and colour.
     *
     * @param  array<string, mixed>  $signals
     * @return list<array{flag: string, label: string, hint: string, color: string}>
     */
    public static function flags(array $signals): array
    {
        $cfg = (array) config('leagues.monthly.signals', []);
        $values = [
            'low_accuracy' => AdminNumbers::decimal((float) ($cfg['min_accuracy'] ?? 0.55) * 100, 1, trimZeros: true),
            'high_accuracy' => AdminNumbers::decimal((float) ($cfg['max_accuracy'] ?? 0.98) * 100, 1, trimZeros: true),
            'too_fast' => AdminNumbers::decimal((int) ($cfg['min_ms_per_review'] ?? 1500) / 1000, 1, trimZeros: true),
            'young_account' => (string) (int) ($cfg['young_account_days'] ?? 30),
            'spiky' => AdminNumbers::decimal((float) ($cfg['spike_ratio'] ?? 3.0), 1, trimZeros: true),
            'capped_often' => (string) max(1, (int) ($cfg['capped_days'] ?? 5)),
        ];

        $flags = [];
        foreach ((array) ($signals['flags'] ?? []) as $flag) {
            if (! is_string($flag)) {
                continue;
            }
            $flags[] = [
                'flag' => $flag,
                'label' => AdminLabels::value('league_month.flags', $flag),
                'hint' => Lang::has("admin.league_month.flag_hints.{$flag}") ? (string) __("admin.league_month.flag_hints.{$flag}", ['value' => $values[$flag] ?? '']) : '',
                'color' => self::FLAG_COLORS[$flag] ?? 'gray',
            ];
        }

        return $flags;
    }

    /** A Telegram username that is safe to put into a `https://t.me/<username>` link (Telegram's own alphabet), else null. */
    public static function telegramUsername(?string $username): ?string
    {
        $username = ltrim((string) $username, '@');

        return preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) === 1 ? $username : null;
    }
}
