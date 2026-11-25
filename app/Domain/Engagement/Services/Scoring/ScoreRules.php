<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\ScoreLimits;
use App\Domain\Engagement\DTO\ScoreRuleVersion;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoringMode;
use App\Domain\Engagement\Services\LeagueService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * The word-points rules of `config('leagues.scoring')` (docs/32 §6.1): the mode, the immutable versions and which version a
 * period counts by, the rating day of the league zone.
 *
 *   Version 1 is the v1 formula of the leagues (`leagues.points`, `leagues.monthly.daily_cap`); it is implicit and has no entry.
 *   A period (week from Monday, month from the 1st, league zone) counts by the greatest configured version whose `starts_on` is on
 *   or before its first day - in `live` mode only: `off` and `shadow` keep every period on version 1 (shadow records the credits
 *   for calibration but nothing counts). The version a period got is written into it once (`league_weeks.rules_version`,
 *   `league_months.rules_version`, `learner_score_periods.rules_version`) and is what counts from then on - a later change of the
 *   configuration never moves an open period.
 *
 * A malformed version (no valid `starts_on`, a missing weight or limit) never applies: the rules are published and reviewed with the
 * code, so a broken entry is a mistake that must not start counting anything. Nothing is cached: the configuration is in memory.
 */
final class ScoreRules
{
    public function mode(): ScoringMode
    {
        $mode = config('leagues.scoring.mode', 'off');

        return ScoringMode::tryFrom(is_string($mode) ? strtolower(trim($mode)) : '') ?? ScoringMode::Off;
    }

    /** Whether answers are credited at all (`shadow` or `live`). */
    public function records(): bool
    {
        return $this->mode() !== ScoringMode::Off;
    }

    /** Whether game answers are credited (owner's decision В6: yes, with a switch). */
    public function gameEnabled(): bool
    {
        return (bool) config('leagues.scoring.game_enabled', true);
    }

    public function creditsRetentionDays(): int
    {
        return max(1, (int) config('leagues.scoring.credits_retention_days', 180));
    }

    /** The zone of the rating day: the league's (`leagues.timezone`, Tashkent). */
    public function timezone(): DateTimeZone
    {
        return LeagueService::leagueZone();
    }

    /** The rating day of a moment: its calendar date in the league zone, 00:00 of it (docs/32 §3.4 - the server's moment of acceptance). */
    public function ratingDay(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone($this->timezone())->startOfDay();
    }

    /** The first day of the period that contains the rating day: Monday of the week, the 1st of the month. */
    public function periodStart(ScorePeriod $period, CarbonInterface $day): CarbonImmutable
    {
        $local = CarbonImmutable::instance($day)->setTimezone($this->timezone());

        return match ($period) {
            ScorePeriod::Week => $local->startOfWeek(CarbonInterface::MONDAY),
            ScorePeriod::Month => $local->startOfMonth(),
        };
    }

    /**
     * The rules version of a period that starts on `$periodStart` (`Y-m-d` or a moment of that day in the league zone), as it would
     * be fixed NOW: 1 unless the mode is `live` and a version has started by then.
     */
    public function versionFor(CarbonInterface|string $periodStart): int
    {
        if ($this->mode() !== ScoringMode::Live) {
            return 1;
        }

        $day = is_string($periodStart)
            ? $periodStart
            : CarbonImmutable::instance($periodStart)->setTimezone($this->timezone())->toDateString();

        $version = 1;
        foreach ($this->versions() as $number => $rules) {
            if ($rules->startsOn !== null && $rules->startsOn <= $day && $number > $version) {
                $version = $number;
            }
        }

        return $version;
    }

    /** The rules of a version >= 2; null for version 1 (the v1 formula) and for a version that is not configured or malformed. */
    public function version(int $version): ?ScoreRuleVersion
    {
        return $this->versions()[$version] ?? null;
    }

    /**
     * The rules the kind and the raw points of a credit are told by (docs/32 §12.1): the higher version of the day's two counts, or -
     * when both are still on version 1 (`shadow`, `live` before the start) - the latest configured version, so that the recorded facts
     * are those of the rules to come. Null only when no version is configured at all (nothing is recorded then).
     */
    public function reference(int $weekVersion, int $monthVersion): ?ScoreRuleVersion
    {
        $latest = $this->versions();

        return $this->version(max($weekVersion, $monthVersion)) ?? (array_pop($latest) ?: null);
    }

    /**
     * Every well-formed configured version >= 2, by number (a malformed one is left out).
     *
     * @return array<int, ScoreRuleVersion>
     */
    public function versions(): array
    {
        $out = [];
        foreach ((array) config('leagues.scoring.versions', []) as $number => $raw) {
            if (! is_int($number) || $number < 2 || ! is_array($raw)) {
                continue;
            }

            $parsed = $this->parse($number, $raw);
            if ($parsed !== null) {
                $out[$number] = $parsed;
            }
        }
        ksort($out);

        return $out;
    }

    /** @param  array<mixed>  $raw */
    private function parse(int $number, array $raw): ?ScoreRuleVersion
    {
        $points = is_array($raw['points'] ?? null) ? $raw['points'] : [];
        $limits = is_array($raw['limits'] ?? null) ? $raw['limits'] : [];

        $weights = [];
        foreach (['new', 'review', 'long'] as $kind) {
            if (! is_numeric($points[$kind] ?? null) || (int) $points[$kind] < 0) {
                return null;
            }
            $weights[$kind] = (int) $points[$kind];
        }

        $byCategory = [];
        foreach (['free', 'pro'] as $category) {
            $row = is_array($limits[$category] ?? null) ? $limits[$category] : [];
            foreach (['new', 'review', 'day_cap'] as $limit) {
                if (! is_numeric($row[$limit] ?? null) || (int) $row[$limit] < 0) {
                    return null;
                }
            }
            $byCategory[$category] = new ScoreLimits((int) $row['new'], (int) $row['review'], (int) $row['day_cap']);
        }

        if (! is_numeric($raw['long_interval_days'] ?? null) || ! is_numeric($raw['best_days'] ?? null)) {
            return null;
        }

        return new ScoreRuleVersion(
            version: $number,
            startsOn: self::date($raw['starts_on'] ?? null),
            points: $weights,
            longIntervalDays: max(1, (int) $raw['long_interval_days']),
            bestDays: max(1, (int) $raw['best_days']),
            limits: $byCategory,
        );
    }

    /** A valid `Y-m-d`, or null (not scheduled). */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== null && $date->format('Y-m-d') === trim($value) ? trim($value) : null;
    }
}
