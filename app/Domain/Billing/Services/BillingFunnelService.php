<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Analytics\Enums\ProductEventName;
use App\Models\Payment;
use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The Pro funnel of the learners (docs/13 §5.1, audit 2026-11-18 §4): «an offer was shown → a checkout started → a payment succeeded →
 * the learner studied again» over a window of `$days` (default 30) ending now, in unique learners, by provider and locale, plus what
 * became of the orders and where the offers were seen. Read-only, from `product_events` and `payment_orders`.
 *
 * Definitions (W = [now - days, now); every event is the learner's own: `learner_id` set):
 *  - VIEWED: learners with `billing.offer_viewed` in W. The learner's SEGMENT LOCALE is the `locale` of their first view in W
 *    (a learner is in exactly one locale, so the totals are sums of the locales);
 *  - STARTED (provider P): viewed learners with `checkout.started` of P in W at or after their first view. The row `all` counts
 *    a learner who started with two providers once;
 *  - PAID (P): started learners with `payment.succeeded` of P, `kind = charge` (a renewal is not a decision to buy), in W at or after
 *    their first such start;
 *  - MATURING: paid learners whose 7 days after their first such payment are not over yet (they may still study);
 *  - STUDIED: MATURED paid learners (paid − maturing) with `training.session_opened` within 7 days after their first such payment,
 *    (paid_at, paid_at + 7 d]. A maturing learner who studied already is not counted yet: the share studied / (paid − maturing) is
 *    over learners whose 7 days are all known, so it does not move up as the young payments age;
 *  - OUTSIDE: starts and payments of W with no step before them (no recorded view before the start: the bot, an old client,
 *    a learner who bought before the event existed), per provider and the locale of the event itself;
 *  - PLACEMENTS: views of W by `placement` (learners and events);
 *  - ORDERS: learner orders created in W by provider, `meta.learner_locale`, status and reason (`canceled`: `meta.canceled_reason`,
 *    `failed`: `checkout_error` when the provider did not give a link) - the "lost between start and success" part;
 *  - TRIAL OFFERS (docs/34 §2д, P8): per step of the chain after the welcome trial (0 / 3 / 10), the learners who got it in W
 *    (`trial_offer.sent`) and the learners whose first payment (`payment.succeeded`, `kind = charge`) in W came after an offer, credited
 *    to the LAST step they got before paying (so a learner is paid in one step only); `opted_out`: learners with `trial_offer.opted_out`
 *    in W.
 *
 * Five statements, whatever the volume; the JSON props are read with fixed keys, every value is a binding. Works on PostgreSQL
 * (production) and sqlite (tests). The result is an array of scalars in the pure cache ({@see WidgetCache}, key
 * `admin:widget:billing_funnel:v3:all:d<days>`; v2: `studied` counts matured payers only, v3: `trial_offers`, fresh {@see CACHE_TTL} s, then served stale while it is recomputed).
 *
 * @phpstan-type FunnelRow array{provider: string, locale: string, viewed: int, started: int, paid: int, studied: int, maturing: int}
 * @phpstan-type OutsideRow array{provider: string, locale: string, started: int, paid: int}
 * @phpstan-type PlacementRow array{placement: string, learners: int, views: int}
 * @phpstan-type OrderRow array{provider: string, locale: string, status: string, reason: string|null, orders: int, learners: int}
 * @phpstan-type TrialOfferRow array{step: int, sent: int, paid: int}
 * @phpstan-type TrialOffers array{steps: list<TrialOfferRow>, opted_out: int}
 * @phpstan-type FunnelReport array{generated_at: string, days: int, funnel: list<FunnelRow>, outside: list<OutsideRow>, placements: list<PlacementRow>, orders: list<OrderRow>, trial_offers: TrialOffers}
 */
final class BillingFunnelService
{
    public const CACHE_WIDGET = 'billing_funnel';

    /** Seconds the figures are fresh (docs/19 §3: minutes; a funnel does not need to be live). */
    public const CACHE_TTL = 600;

    public const DEFAULT_DAYS = 30;

    /** The window of the last step: a training session within this many days after the payment. */
    public const STUDY_DAYS = 7;

    /** The provider of a row over every provider, and the locale of the total row. */
    public const ALL = 'all';

    /** A locale / provider / placement / reason the event did not carry. */
    public const UNKNOWN = 'unknown';

    public function __construct(private readonly WidgetCache $cache) {}

    /**
     * The report from the cache; computed once per {@see CACHE_TTL}.
     *
     * @return FunnelReport
     */
    public function report(int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, $days);
        $cached = $this->cache->remember(self::CACHE_WIDGET, 'all', self::CACHE_TTL, fn (): array => $this->compute(CarbonImmutable::now(), $days), 'd'.$days);

        if (! self::isReport($cached)) {
            // Whatever sits under the key is not a report of this version: drop it and compute again.
            $this->forget($days);
            $cached = $this->cache->remember(self::CACHE_WIDGET, 'all', self::CACHE_TTL, fn (): array => $this->compute(CarbonImmutable::now(), $days), 'd'.$days);
        }

        return self::normalize($cached);
    }

    public function forget(int $days = self::DEFAULT_DAYS): void
    {
        $this->cache->forget(self::CACHE_WIDGET, 'all', 'd'.max(1, $days));
    }

    /**
     * The figures as of `$now`, from the database (no cache).
     *
     * @return FunnelReport
     */
    public function compute(CarbonImmutable $now, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, $days);
        $connection = DB::connection();
        $pgsql = self::isPgsql($connection);
        $to = self::db($now);
        $from = self::db($now->subDays($days));

        [$funnel, $outside] = $this->chain($connection, $pgsql, $from, $to, $now);

        return [
            'generated_at' => $now->toIso8601String(),
            'days' => $days,
            'funnel' => $funnel,
            'outside' => $outside,
            'placements' => $this->placements($connection, $pgsql, $from, $to),
            'orders' => $this->orders($connection, $pgsql, $from, $to),
            'trial_offers' => $this->trialOffers($connection, $pgsql, $from, $to),
        ];
    }

    /**
     * Statement 1: the chain, in one query of CTEs; the rows are (step, provider, locale, learners, studied, maturing).
     *
     * @return array{list<FunnelRow>, list<OutsideRow>}
     */
    private function chain(Connection $connection, bool $pgsql, string $from, string $to, CarbonImmutable $now): array
    {
        $locale = self::json($pgsql, 'e.props', 'locale');
        $provider = self::json($pgsql, 'e.props', 'provider');
        $kind = self::json($pgsql, 'e.props', 'kind');
        $studyEnd = $pgsql ? 'd.paid_at + CAST(? AS integer) * interval \'1 day\'' : 'datetime(d.paid_at, ?)';
        $window = 'e.occurred_at >= ? AND e.occurred_at < ? AND e.learner_id IS NOT NULL';
        $all = self::ALL;
        $unknown = self::UNKNOWN;

        $sql = <<<SQL
            WITH views AS (
                SELECT e.id, e.learner_id, e.occurred_at, {$locale} AS locale
                FROM product_events e WHERE e.name = ? AND {$window}
            ), first_views AS (
                SELECT r.learner_id, r.occurred_at AS viewed_at, COALESCE(r.locale, '{$unknown}') AS locale
                FROM (
                    SELECT v.learner_id, v.occurred_at, v.locale, ROW_NUMBER() OVER (PARTITION BY v.learner_id ORDER BY v.occurred_at, v.id) AS rn
                    FROM views v
                ) r
                WHERE r.rn = 1
            ), starts AS (
                SELECT e.learner_id, e.occurred_at, COALESCE({$provider}, '{$unknown}') AS provider, COALESCE({$locale}, '{$unknown}') AS locale
                FROM product_events e WHERE e.name = ? AND {$window}
            ), pays AS (
                SELECT e.learner_id, e.occurred_at, COALESCE({$provider}, '{$unknown}') AS provider, COALESCE({$locale}, '{$unknown}') AS locale
                FROM product_events e WHERE e.name = ? AND {$window} AND {$kind} = ?
            ), chain_started AS (
                SELECT f.learner_id, f.locale, s.provider, MIN(s.occurred_at) AS started_at
                FROM first_views f JOIN starts s ON s.learner_id = f.learner_id AND s.occurred_at >= f.viewed_at
                GROUP BY f.learner_id, f.locale, s.provider
            ), chain_paid AS (
                SELECT c.learner_id, c.locale, c.provider, MIN(p.occurred_at) AS paid_at
                FROM chain_started c JOIN pays p ON p.learner_id = c.learner_id AND p.provider = c.provider AND p.occurred_at >= c.started_at
                GROUP BY c.learner_id, c.locale, c.provider
            ), chain_studied AS (
                SELECT d.learner_id, d.locale, d.provider,
                    CASE WHEN d.paid_at <= ? AND EXISTS (
                        SELECT 1 FROM product_events o
                        WHERE o.name = ? AND o.learner_id = d.learner_id AND o.occurred_at > d.paid_at AND o.occurred_at <= {$studyEnd}
                    ) THEN 1 ELSE 0 END AS studied,
                    CASE WHEN d.paid_at > ? THEN 1 ELSE 0 END AS maturing
                FROM chain_paid d
            )
            SELECT 'viewed' AS step, '{$all}' AS provider, locale, COUNT(*) AS learners, 0 AS studied, 0 AS maturing FROM first_views GROUP BY locale
            UNION ALL
            SELECT 'started', provider, locale, COUNT(*), 0, 0 FROM chain_started GROUP BY provider, locale
            UNION ALL
            SELECT 'started', '{$all}', locale, COUNT(DISTINCT learner_id), 0, 0 FROM chain_started GROUP BY locale
            UNION ALL
            SELECT 'paid', provider, locale, COUNT(*), SUM(studied), SUM(maturing) FROM chain_studied GROUP BY provider, locale
            UNION ALL
            SELECT 'paid', '{$all}', x.locale, COUNT(*), SUM(x.studied), SUM(x.maturing)
            FROM (SELECT learner_id, locale, MAX(studied) AS studied, MIN(maturing) AS maturing FROM chain_studied GROUP BY learner_id, locale) x
            GROUP BY x.locale
            UNION ALL
            SELECT 'started_outside', s.provider, s.locale, COUNT(DISTINCT s.learner_id), 0, 0 FROM starts s
            WHERE NOT EXISTS (SELECT 1 FROM chain_started c WHERE c.learner_id = s.learner_id AND c.provider = s.provider)
            GROUP BY s.provider, s.locale
            UNION ALL
            SELECT 'paid_outside', p.provider, p.locale, COUNT(DISTINCT p.learner_id), 0, 0 FROM pays p
            WHERE NOT EXISTS (SELECT 1 FROM chain_paid c WHERE c.learner_id = p.learner_id AND c.provider = p.provider)
            GROUP BY p.provider, p.locale
            SQL;

        // A payment at or before this moment has its 7 days behind it.
        $matured = self::db($now->subDays(self::STUDY_DAYS));
        $rows = $connection->select($sql, [
            ProductEventName::BillingOfferViewed->value, $from, $to,
            ProductEventName::CheckoutStarted->value, $from, $to,
            ProductEventName::PaymentSucceeded->value, $from, $to, Payment::KIND_CHARGE,
            $matured,
            ProductEventName::TrainingSessionOpened->value, $pgsql ? self::STUDY_DAYS : '+'.self::STUDY_DAYS.' days',
            $matured,
        ]);

        /** @var array<string, array<string, array{viewed: int, started: int, paid: int, studied: int, maturing: int}>> $cells provider => locale => figures */
        $cells = [];
        /** @var array<string, int> $viewed locale => learners */
        $viewed = [];
        /** @var array<string, array<string, array{started: int, paid: int}>> $outside */
        $outside = [];
        $blank = ['viewed' => 0, 'started' => 0, 'paid' => 0, 'studied' => 0, 'maturing' => 0];

        foreach ($rows as $row) {
            $row = (array) $row;
            $step = (string) $row['step'];
            $rowProvider = (string) $row['provider'];
            $rowLocale = (string) $row['locale'];
            $learners = (int) $row['learners'];

            if ($step === 'viewed') {
                $viewed[$rowLocale] = $learners;

                continue;
            }

            if ($step === 'started_outside' || $step === 'paid_outside') {
                $outside[$rowProvider][$rowLocale] ??= ['started' => 0, 'paid' => 0];
                $outside[$rowProvider][$rowLocale][$step === 'started_outside' ? 'started' : 'paid'] = $learners;

                continue;
            }

            $cells[$rowProvider][$rowLocale] ??= $blank;
            if ($step === 'started') {
                $cells[$rowProvider][$rowLocale]['started'] = $learners;
            } else {
                $cells[$rowProvider][$rowLocale]['paid'] = $learners;
                $cells[$rowProvider][$rowLocale]['studied'] = (int) $row['studied'];
                $cells[$rowProvider][$rowLocale]['maturing'] = (int) $row['maturing'];
            }
        }

        // Every locale with a view has a row in every provider, even with nobody further down: a provider's total then measures
        // its starts against all the views, as the row "all providers" does.
        $cells[self::ALL] ??= [];
        foreach (array_keys($cells) as $rowProvider) {
            foreach (array_keys($viewed) as $viewLocale) {
                $cells[$rowProvider][$viewLocale] ??= $blank;
            }
        }

        $funnel = [];
        foreach (self::sortedKeys($cells, self::ALL) as $rowProvider) {
            $byLocale = $cells[$rowProvider];
            $total = $blank;
            $lines = [];

            foreach (self::sortedKeys($byLocale) as $rowLocale) {
                // The first step has no provider: a provider's row measures its starts against the locale's views.
                $figures = ['viewed' => $viewed[$rowLocale] ?? 0] + $byLocale[$rowLocale];
                $lines[] = ['provider' => (string) $rowProvider, 'locale' => (string) $rowLocale, ...$figures];

                foreach ($figures as $key => $value) {
                    $total[$key] += $value;
                }
            }

            // A learner sits in one locale (that of the first view), so the total of a provider is the sum of its locales.
            $funnel[] = ['provider' => (string) $rowProvider, 'locale' => self::ALL, ...$total];
            array_push($funnel, ...$lines);
        }

        $outsideRows = [];
        foreach (self::sortedKeys($outside) as $rowProvider) {
            foreach (self::sortedKeys($outside[$rowProvider]) as $rowLocale) {
                $outsideRows[] = ['provider' => (string) $rowProvider, 'locale' => (string) $rowLocale, ...$outside[$rowProvider][$rowLocale]];
            }
        }

        return [$funnel, $outsideRows];
    }

    /**
     * Statement 2: the views by placement.
     *
     * @return list<PlacementRow>
     */
    private function placements(Connection $connection, bool $pgsql, string $from, string $to): array
    {
        $placement = self::json($pgsql, 'e.props', 'placement');
        $unknown = self::UNKNOWN;

        $rows = $connection->select(
            "SELECT COALESCE({$placement}, '{$unknown}') AS placement, COUNT(DISTINCT e.learner_id) AS learners, COUNT(*) AS views
            FROM product_events e
            WHERE e.name = ? AND e.occurred_at >= ? AND e.occurred_at < ? AND e.learner_id IS NOT NULL
            GROUP BY 1
            ORDER BY 2 DESC, 1",
            [ProductEventName::BillingOfferViewed->value, $from, $to],
        );

        return array_values(array_map(static function (object $row): array {
            $row = (array) $row;

            return ['placement' => (string) $row['placement'], 'learners' => (int) $row['learners'], 'views' => (int) $row['views']];
        }, $rows));
    }

    /**
     * Statement 3: the learner orders created in the window, by provider, locale, status and reason.
     *
     * @return list<OrderRow>
     */
    private function orders(Connection $connection, bool $pgsql, string $from, string $to): array
    {
        $locale = self::json($pgsql, 'o.meta', 'learner_locale');
        $canceled = self::json($pgsql, 'o.meta', 'canceled_reason');
        $checkoutError = self::json($pgsql, 'o.meta', 'checkout_error');
        $unknown = self::UNKNOWN;

        $rows = $connection->select(
            "SELECT o.provider, COALESCE({$locale}, '{$unknown}') AS locale, o.status,
                CASE
                    WHEN o.status = ? THEN COALESCE({$canceled}, '{$unknown}')
                    WHEN o.status = ? THEN CASE WHEN {$checkoutError} IS NOT NULL THEN 'checkout_error' ELSE '{$unknown}' END
                    ELSE NULL
                END AS reason,
                COUNT(*) AS orders, COUNT(DISTINCT o.subscriber_id) AS learners
            FROM payment_orders o
            WHERE o.subscriber_type = ? AND o.created_at >= ? AND o.created_at < ?
            GROUP BY 1, 2, 3, 4
            ORDER BY 1, 2, 3, 4",
            [PaymentOrder::STATUS_CANCELED, PaymentOrder::STATUS_FAILED, 'learner', $from, $to],
        );

        return array_values(array_map(static function (object $row): array {
            $row = (array) $row;

            return [
                'provider' => (string) $row['provider'],
                'locale' => (string) $row['locale'],
                'status' => (string) $row['status'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'orders' => (int) $row['orders'],
                'learners' => (int) $row['learners'],
            ];
        }, $rows));
    }

    /**
     * Statements 4 and 5: the chain of offers after the welcome trial - learners per step, the payments credited to the last step
     * before them, and the opt-outs.
     *
     * @return TrialOffers
     */
    private function trialOffers(Connection $connection, bool $pgsql, string $from, string $to): array
    {
        $step = self::json($pgsql, 'e.props', 'step');
        $kind = self::json($pgsql, 'e.props', 'kind');
        $window = 'e.occurred_at >= ? AND e.occurred_at < ? AND e.learner_id IS NOT NULL';

        $sql = <<<SQL
            WITH sends AS (
                SELECT e.id, e.learner_id, e.occurred_at, {$step} AS step
                FROM product_events e WHERE e.name = ? AND {$window}
            ), pays AS (
                SELECT e.learner_id, MIN(e.occurred_at) AS paid_at
                FROM product_events e
                WHERE e.name = ? AND {$window} AND {$kind} = ?
                    AND EXISTS (SELECT 1 FROM sends s WHERE s.learner_id = e.learner_id AND s.occurred_at <= e.occurred_at)
                GROUP BY e.learner_id
            ), credited AS (
                SELECT p.learner_id, (
                    SELECT s.step FROM sends s
                    WHERE s.learner_id = p.learner_id AND s.occurred_at <= p.paid_at
                    ORDER BY s.occurred_at DESC, s.id DESC
                    LIMIT 1
                ) AS step
                FROM pays p
            )
            SELECT 'sent' AS kind, step, COUNT(DISTINCT learner_id) AS learners FROM sends GROUP BY step
            UNION ALL
            SELECT 'paid', step, COUNT(*) FROM credited GROUP BY step
            SQL;

        $rows = $connection->select($sql, [
            ProductEventName::TrialOfferSent->value, $from, $to,
            ProductEventName::PaymentSucceeded->value, $from, $to, Payment::KIND_CHARGE,
        ]);

        /** @var array<int, array{step: int, sent: int, paid: int}> $steps */
        $steps = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (! is_numeric($row['step'])) {
                continue;
            }

            $key = (int) $row['step'];
            $steps[$key] ??= ['step' => $key, 'sent' => 0, 'paid' => 0];
            $steps[$key][(string) $row['kind'] === 'sent' ? 'sent' : 'paid'] += (int) $row['learners'];
        }
        ksort($steps);

        $optedOut = $connection->selectOne(
            'SELECT COUNT(DISTINCT e.learner_id) AS learners FROM product_events e WHERE e.name = ? AND '.$window,
            [ProductEventName::TrialOfferOptedOut->value, $from, $to],
        );

        return ['steps' => array_values($steps), 'opted_out' => (int) (((array) $optedOut)['learners'] ?? 0)];
    }

    /** The text of a top-level key of a JSON column; `$key` is a constant of this class, never input. */
    private static function json(bool $pgsql, string $column, string $key): string
    {
        return $pgsql ? "({$column}->>'{$key}')" : "json_extract({$column}, '$.{$key}')";
    }

    private static function isPgsql(Connection $connection): bool
    {
        return match ($driver = $connection->getDriverName()) {
            'pgsql' => true,
            'sqlite' => false,
            default => throw new LogicException("The billing funnel supports pgsql and sqlite, not '{$driver}'."),
        };
    }

    /**
     * The keys of a map sorted, with `$first` (when present) ahead of the rest.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<array-key>
     */
    private static function sortedKeys(array $map, ?string $first = null): array
    {
        $keys = array_keys($map);
        usort($keys, static fn (int|string $a, int|string $b): int => [(string) $a !== $first, (string) $a] <=> [(string) $b !== $first, (string) $b]);

        return $keys;
    }

    /** @param  array<array-key, mixed>  $report */
    private static function isReport(array $report): bool
    {
        return isset($report['generated_at'], $report['days'], $report['funnel'], $report['outside'], $report['placements'], $report['orders'], $report['trial_offers'])
            && is_string($report['generated_at']) && is_array($report['funnel']) && is_array($report['outside'])
            && is_array($report['placements']) && is_array($report['orders']) && is_array($report['trial_offers']);
    }

    /**
     * Cached figures back to their types (docs/19 §3 rule 11: be strict on what the cache hands back).
     *
     * @param  array<array-key, mixed>  $report
     * @return FunnelReport
     */
    private static function normalize(array $report): array
    {
        $int = static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
        $offers = is_array($report['trial_offers'] ?? null) ? $report['trial_offers'] : [];

        return [
            'generated_at' => $str($report['generated_at']),
            'days' => $int($report['days']),
            'funnel' => array_map(static fn (array $r): array => [
                'provider' => $str($r['provider'] ?? ''), 'locale' => $str($r['locale'] ?? ''),
                'viewed' => $int($r['viewed'] ?? 0), 'started' => $int($r['started'] ?? 0), 'paid' => $int($r['paid'] ?? 0),
                'studied' => $int($r['studied'] ?? 0), 'maturing' => $int($r['maturing'] ?? 0),
            ], $list($report['funnel'])),
            'outside' => array_map(static fn (array $r): array => [
                'provider' => $str($r['provider'] ?? ''), 'locale' => $str($r['locale'] ?? ''),
                'started' => $int($r['started'] ?? 0), 'paid' => $int($r['paid'] ?? 0),
            ], $list($report['outside'])),
            'placements' => array_map(static fn (array $r): array => [
                'placement' => $str($r['placement'] ?? ''), 'learners' => $int($r['learners'] ?? 0), 'views' => $int($r['views'] ?? 0),
            ], $list($report['placements'])),
            'orders' => array_map(static fn (array $r): array => [
                'provider' => $str($r['provider'] ?? ''), 'locale' => $str($r['locale'] ?? ''), 'status' => $str($r['status'] ?? ''),
                'reason' => isset($r['reason']) && is_scalar($r['reason']) ? (string) $r['reason'] : null,
                'orders' => $int($r['orders'] ?? 0), 'learners' => $int($r['learners'] ?? 0),
            ], $list($report['orders'])),
            'trial_offers' => [
                'steps' => array_map(static fn (array $r): array => [
                    'step' => $int($r['step'] ?? 0), 'sent' => $int($r['sent'] ?? 0), 'paid' => $int($r['paid'] ?? 0),
                ], $list($offers['steps'] ?? [])),
                'opted_out' => $int($offers['opted_out'] ?? 0),
            ],
        ];
    }

    /** A moment as the database holds it: UTC `Y-m-d H:i:s`. */
    private static function db(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i:s');
    }
}
