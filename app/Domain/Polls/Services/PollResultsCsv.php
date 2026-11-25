<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Organizations\Services\OrgActivityReportCsv;
use App\Models\AuditLog;
use App\Models\Poll;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The CSV files of the results page of a poll (docs/35 §1.5 "Результаты", O7), in the format of {@see OrgActivityReportCsv}: UTF-8
 * with BOM, `;`, CRLF, headers in Russian (`admin.polls.csv.*`); a cell that would start a spreadsheet formula (`= + - @`, tab, CR)
 * gets a leading `'` - the options are typed by an administrator.
 *
 *  - {@see writeSummary()}: one line per option - votes and the share of the answered;
 *  - {@see writeAnswers()}: one line per answered delivery - delivery id, learner id, language, Free/Pro (now), region, the chosen
 *    options, the time of the answer. No names nor usernames (docs/35 О2): the page shows who, the file does not.
 *
 * The answers are STREAMED: deliveries by {@see CHUNK} along `poll_deliveries (poll_id, learner_id)` (keyset on the learner id: an
 * index range in order, no sort, no OFFSET), per chunk one read of its votes (by the unique `(poll_delivery_id, poll_option_id)`)
 * and one of its learners with the Pro test narrowed to the chunk - 3 statements per 1 000 rows, never the whole poll in memory.
 * Every export is written to the audit log ({@see record()}) before the first byte.
 */
final class PollResultsCsv
{
    public const CHUNK = 1000;

    public const AUDIT_EXPORTED = 'poll.results_exported';

    public function __construct(private readonly OrgActivityReportCsv $csv) {}

    /**
     * @param  resource  $out
     * @param  array{answered: int, options: list<array{label: string, votes: int, share: float}>}  $summary  {@see PollReport::summary()}
     */
    public function writeSummary($out, array $summary): void
    {
        $this->csv->bom($out);
        $this->csv->line($out, [__('admin.polls.csv.option'), __('admin.polls.csv.votes'), __('admin.polls.csv.share')]);

        foreach ($summary['options'] as $option) {
            $this->csv->line($out, [$option['label'], $option['votes'], str_replace('.', ',', (string) $option['share'])]);
        }

        $this->csv->line($out, [__('admin.polls.csv.answered'), $summary['answered'], '']);
    }

    /** @param resource $out */
    public function writeAnswers($out, Poll $poll, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $timezone = (string) config('learners.default_timezone', 'Asia/Tashkent');
        $labels = [];
        foreach ($poll->options()->get(['id', 'position', 'label']) as $option) {
            $labels[(int) $option->id] = [(int) $option->position, (string) ($option->label['ru'] ?? '')];
        }

        $this->csv->bom($out);
        $this->csv->line($out, array_map(static fn (string $key): string => (string) __('admin.polls.csv.'.$key), [
            'delivery_id', 'learner_id', 'locale', 'plan', 'region', 'options', 'answered_at',
        ]));

        DB::table('poll_deliveries')
            ->where('poll_id', $poll->id)
            ->whereNotNull('learner_id')
            ->whereNotNull('answered_at')
            ->select(['id', 'learner_id', 'locale', 'answered_at'])
            ->chunkById(self::CHUNK, function ($rows) use ($out, $labels, $now, $timezone): void {
                $deliveryIds = [];
                $learnerIds = [];
                foreach ($rows as $row) {
                    $deliveryIds[] = (int) $row->id;
                    $learnerIds[] = (int) $row->learner_id;
                }

                $chosen = [];
                foreach (DB::table('poll_votes')->whereIn('poll_delivery_id', $deliveryIds)->get(['poll_delivery_id', 'poll_option_id']) as $vote) {
                    $chosen[(int) $vote->poll_delivery_id][] = (int) $vote->poll_option_id;
                }

                $learners = $this->learners($learnerIds, $now);

                foreach ($rows as $row) {
                    $options = $chosen[(int) $row->id] ?? [];
                    usort($options, static fn (int $a, int $b): int => ($labels[$a][0] ?? 0) <=> ($labels[$b][0] ?? 0));
                    $learner = $learners[(int) $row->learner_id] ?? ['region' => '', 'pro' => false];

                    $this->csv->line($out, [
                        (int) $row->id,
                        (int) $row->learner_id,
                        (string) $row->locale,
                        $learner['pro'] ? 'Pro' : 'Free',
                        $learner['region'],
                        implode(' | ', array_map(static fn (int $id): string => $labels[$id][1] ?? '#'.$id, $options)),
                        CarbonImmutable::parse((string) $row->answered_at, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i'),
                    ]);
                }
            }, 'learner_id');
    }

    /** The audit record of an export (docs/35 §1.6: "выгрузка CSV с ответами"), written before the file goes out. */
    public function record(Poll $poll, User $by, string $kind, ?string $ip = null): void
    {
        AuditLog::query()->create([
            'user_id' => $by->id,
            'action' => self::AUDIT_EXPORTED,
            'entity_type' => Poll::class,
            'entity_id' => $poll->id,
            'ip' => $ip,
            'meta' => ['kind' => $kind],
        ]);
    }

    public function filename(Poll $poll, string $kind): string
    {
        return sprintf('poll-%d-%s.csv', $poll->id, $kind === 'answers' ? 'answers' : 'summary');
    }

    /**
     * Region and Pro of the learners of one chunk: one statement, the Pro subqueries narrowed to the chunk.
     *
     * @param  list<int>  $ids
     * @return array<int, array{region: string, pro: bool}>
     */
    private function learners(array $ids, CarbonImmutable $now): array
    {
        $personal = PaidSubscriptionQuery::personalLearnerIds($now)->whereIn('subscriptions.subscriber_id', $ids);
        $sponsored = PaidSubscriptionQuery::sponsoredLearnerIds($now)->whereIn('om.learner_id', $ids);

        $rows = DB::table('learners')
            ->whereIn('id', $ids)
            ->select(['id', 'region_code'])
            ->selectRaw(
                'CASE WHEN id IN ('.$personal->toSql().') OR id IN ('.$sponsored->toSql().') THEN 1 ELSE 0 END AS pro',
                [...$personal->getBindings(), ...$sponsored->getBindings()],
            )
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = ['region' => (string) ($row->region_code ?? ''), 'pro' => (int) $row->pro === 1];
        }

        return $out;
    }
}
