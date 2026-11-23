<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The monthly character budget of the `google` driver (docs/26 §5.9, §9.3): a bug or a loop must not spend past the free
 * tier. One row per month in `tts_usage_months` (PostgreSQL, not Redis: this is money, and Redis may be flushed). The
 * characters are reserved BEFORE the call with one conditional UPDATE - the database re-checks the WHERE after locking the
 * row, so two workers cannot pass the limit together - and given back when Google surely did not synthesize (§9.2).
 *
 * The month is the billing month of Google: America/Los_Angeles, not UTC. Never call {@see reserve()} inside a transaction
 * of someone else: a rollback would give back characters Google has already counted.
 */
final class TtsCharacterBudget
{
    public const TABLE = 'tts_usage_months';

    public const TIMEZONE = 'America/Los_Angeles';

    /** The billing month, `Y-m` in Pacific time. */
    public function month(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m');
    }

    public function limit(): int
    {
        return (int) config('twa.tts.monthly_char_limit', 900_000);
    }

    /**
     * Reserves `$chars` characters of the current month.
     *
     * @return string the month of the reservation (give it back to {@see refund()}: the answer may come in the next month)
     *
     * @throws SpeechSynthesisException tts_monthly_limit_reached
     */
    public function reserve(int $chars): string
    {
        $month = $this->month();
        $limit = $this->limit();
        $chars = max(0, $chars);
        if ($limit <= 0 || $chars > $limit) {
            throw SpeechSynthesisException::monthlyLimitReached();
        }

        $now = now();
        DB::table(self::TABLE)->insertOrIgnore([
            'month' => $month,
            'characters' => 0,
            'requests' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $reserved = DB::table(self::TABLE)
            ->where('month', $month)
            ->where('characters', '<=', $limit - $chars)
            ->incrementEach(['characters' => $chars, 'requests' => 1], ['updated_at' => $now]);

        if ($reserved === 0) {
            throw SpeechSynthesisException::monthlyLimitReached();
        }

        return $month;
    }

    /** Gives back a reservation that Google did not spend; `$month` is the one {@see reserve()} returned. */
    public function refund(string $month, int $chars): void
    {
        $chars = max(0, $chars);

        DB::table(self::TABLE)
            ->where('month', $month)
            ->where('characters', '>=', $chars)
            ->where('requests', '>=', 1)
            ->decrementEach(['characters' => $chars, 'requests' => 1], ['updated_at' => now()]);
    }

    /** @return array{month: string, characters: int, limit: int} */
    public function usage(): array
    {
        $month = $this->month();
        $characters = DB::table(self::TABLE)->where('month', $month)->value('characters');

        return [
            'month' => $month,
            'characters' => (int) $characters,
            'limit' => $this->limit(),
        ];
    }

    /** Nothing more may be synthesized this month (the nightly reconciliation queues nothing then). */
    public function exhausted(): bool
    {
        $usage = $this->usage();

        return $usage['limit'] <= 0 || $usage['characters'] >= $usage['limit'];
    }
}
