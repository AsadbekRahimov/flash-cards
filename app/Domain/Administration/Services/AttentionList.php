<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Content\Services\CourseAudioCoverageStats;
use App\Domain\Learners\Services\ReminderExperiment;
use App\Domain\Organizations\Services\OrgActivationStats;
use Carbon\CarbonImmutable;

/**
 * «Требуют внимания» of the dashboard of `/admin`: what someone should look at today, from figures the dashboard already keeps —
 * no query of its own, so a warm open costs nothing:
 *  - payments a webhook could not apply in 24 hours and the anomalies of the orders ({@see AdminDashboardStats::webhookErrors24h()},
 *    read at the data version of the ledger) — urgent;
 *  - active centers with no assignment completed by a learner {@see CENTER_WAIT_DAYS} days or more after their creation
 *    ({@see OrgActivationStats}), the oldest {@see CENTERS_SHOWN} by name, the others as one line;
 *  - courses published or on review whose audio coverage is below the publishing threshold ({@see CourseAudioCoverageStats});
 *  - the reminder experiment ({@see ReminderExperiment}) while it is switched on: its day, and from day {@see READ_AFTER_DAYS} on
 *    that the report can be read — from the configuration alone (whether the base is large enough takes the full report,
 *    `analytics:reminder-experiment`, which is not run on an open of the dashboard).
 *
 * The items are data (kind, level, numbers, ids); the widget turns them into words and links.
 *
 * @phpstan-type Item array{kind: string, level: string, id: int|null, name: string|null, state: string|null, count: int, value: float|int|null, total: int|null}
 */
final class AttentionList
{
    public const LEVEL_DANGER = 'danger';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    public const KIND_PAYMENTS_FAILED = 'payments_failed';

    public const KIND_PAYMENT_ANOMALIES = 'payment_anomalies';

    public const KIND_CENTER = 'center';

    public const KIND_CENTERS_MORE = 'centers_more';

    public const KIND_AUDIO = 'audio';

    public const KIND_REMINDERS = 'reminder_experiment';

    /** A center waits this many whole days for its first completed assignment before it is on the list. */
    public const CENTER_WAIT_DAYS = 7;

    /** Centers listed one by one; the others are one line. */
    public const CENTERS_SHOWN = 3;

    /** The reminder experiment runs 2-3 weeks (docs/ADMIN_GUIDE.md «Reminder experiment»): from this day of it the report is worth reading. */
    public const READ_AFTER_DAYS = 14;

    public function __construct(
        private readonly AdminDashboardStats $dashboard,
        private readonly OrgActivationStats $centers,
        private readonly CourseAudioCoverageStats $audio,
    ) {}

    /** @return list<Item> the urgent first, then the warnings, then the notes */
    public function items(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $items = [...$this->payments(), ...$this->waitingCenters(), ...$this->audio(), ...$this->reminders($now)];

        $order = [self::LEVEL_DANGER => 0, self::LEVEL_WARNING => 1, self::LEVEL_INFO => 2];
        usort($items, static fn (array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);

        return $items;
    }

    /**
     * The KPI card: how many lines, how many of them urgent, and which kinds there are (in the order of the list).
     *
     * @param  list<Item>  $items
     * @return array{count: int, urgent: int, kinds: list<string>}
     */
    public static function summary(array $items): array
    {
        $kinds = [];
        foreach ($items as $item) {
            $group = match ($item['kind']) {
                self::KIND_PAYMENTS_FAILED, self::KIND_PAYMENT_ANOMALIES => 'payments',
                self::KIND_CENTER, self::KIND_CENTERS_MORE => 'centers',
                self::KIND_AUDIO => 'audio',
                default => 'reminders',
            };
            $kinds[$group] = true;
        }

        return [
            'count' => count($items),
            'urgent' => count(array_filter($items, static fn (array $item): bool => $item['level'] === self::LEVEL_DANGER)),
            'kinds' => array_keys($kinds),
        ];
    }

    /** @return list<Item> */
    private function payments(): array
    {
        $errors = $this->dashboard->webhookErrors24h();
        $items = [];

        if ((int) $errors['failed_payments'] > 0) {
            $items[] = self::item(self::KIND_PAYMENTS_FAILED, self::LEVEL_DANGER, count: (int) $errors['failed_payments']);
        }

        $anomalies = (int) $errors['double_discounts'] + (int) $errors['tier_mismatches'] + (int) $errors['stars_double_subscriptions'];
        if ($anomalies > 0) {
            $items[] = self::item(self::KIND_PAYMENT_ANOMALIES, self::LEVEL_DANGER, count: $anomalies);
        }

        return $items;
    }

    /** @return list<Item> */
    private function waitingCenters(): array
    {
        $waiting = array_values(array_filter(
            $this->centers->figures()['rows'],
            static fn (array $row): bool => $row['status'] === 'active' && $row['completed'] === null && $row['age'] >= self::CENTER_WAIT_DAYS * 86400,
        ));
        // The oldest first, whatever order the cached list is in.
        usort($waiting, static fn (array $a, array $b): int => [-$a['age'], $a['id']] <=> [-$b['age'], $b['id']]);

        $items = [];
        foreach (array_slice($waiting, 0, self::CENTERS_SHOWN) as $row) {
            $items[] = self::item(self::KIND_CENTER, self::LEVEL_WARNING, id: $row['id'], name: $row['name'], value: intdiv($row['age'], 86400));
        }

        $more = count($waiting) - self::CENTERS_SHOWN;
        if ($more > 0) {
            $items[] = self::item(self::KIND_CENTERS_MORE, self::LEVEL_WARNING, count: $more, total: count($waiting));
        }

        return $items;
    }

    /** @return list<Item> */
    private function audio(): array
    {
        return array_map(
            static fn (array $course): array => self::item(self::KIND_AUDIO, self::LEVEL_WARNING, id: $course['id'], name: $course['slug'], count: $course['missing'], value: $course['percent'], total: $course['words']),
            $this->audio->belowThreshold(),
        );
    }

    /** @return list<Item> */
    private function reminders(CarbonImmutable $now): array
    {
        if (! (bool) config('learning.reminders.quiz.experiment.enabled', false)) {
            return [];
        }

        $startsAt = ReminderExperiment::startsAt();

        // Switched on with a start date ahead: not running yet (the day is then negative: days until the start).
        if ($startsAt !== null && $now->isBefore($startsAt)) {
            return [self::item(self::KIND_REMINDERS, self::LEVEL_INFO, state: 'scheduled', value: (int) ceil(($startsAt->getTimestamp() - $now->getTimestamp()) / 86400))];
        }

        // Switched on with no start date (running since then), or with one nobody can read (then it does not split): no day to count.
        if ($startsAt === null) {
            return [self::item(self::KIND_REMINDERS, self::LEVEL_INFO, state: self::hasStartsAt() ? 'invalid' : 'running')];
        }

        $day = intdiv($now->getTimestamp() - $startsAt->getTimestamp(), 86400) + 1;

        return [self::item(
            self::KIND_REMINDERS,
            self::LEVEL_INFO,
            state: $day >= self::READ_AFTER_DAYS ? 'ready' : 'running',
            value: $day,
            total: max(0, self::READ_AFTER_DAYS - $day),
        )];
    }

    private static function hasStartsAt(): bool
    {
        $raw = config('learning.reminders.quiz.experiment.starts_at');

        return is_string($raw) && trim($raw) !== '';
    }

    /** @return Item */
    private static function item(string $kind, string $level, ?int $id = null, ?string $name = null, ?string $state = null, int $count = 0, float|int|null $value = null, ?int $total = null): array
    {
        return ['kind' => $kind, 'level' => $level, 'id' => $id, 'name' => $name, 'state' => $state, 'count' => $count, 'value' => $value, 'total' => $total];
    }
}
