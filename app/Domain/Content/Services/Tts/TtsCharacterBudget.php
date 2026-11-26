<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The character budget of the paid drivers (docs/26 §5.9, §9.3, §10): a bug or a loop must not spend past the free tier or the
 * credit. One row per period in `tts_usage_months` (PostgreSQL, not Redis: this is money, and Redis may be flushed). The
 * characters are reserved BEFORE the call with one conditional UPDATE - the database re-checks the WHERE after locking the
 * row, so two workers cannot pass the limit together - and given back when the service surely did not synthesize (§9.2).
 *
 * The period depends on the driver (`$driver`, by default the one in effect - {@see ConfiguredSpeechSynthesizer::driver()}):
 *  - `deepgram`: ONE row for all time, {@see DEEPGRAM_PERIOD}, against `twa.tts.deepgram.char_limit` - the credit of Deepgram is
 *    one-off, not monthly, so the counter never starts over (a new credit - the owner raises the limit);
 *  - any other: the billing month of Google, `Y-m` in America/Los_Angeles, not UTC, against `twa.tts.monthly_char_limit`.
 *
 * Never call {@see reserve()} inside a transaction of someone else: a rollback would give back characters the service has
 * already counted.
 */
final class TtsCharacterBudget
{
    public const TABLE = 'tts_usage_months';

    public const TIMEZONE = 'America/Los_Angeles';

    /** The key of the one row of the `deepgram` driver: 7 characters like `2026-11` (the column is `char(7)`), never a month. */
    public const DEEPGRAM_PERIOD = 'dgtotal';

    /** The billing month of Google, `Y-m` in Pacific time. */
    public function month(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m');
    }

    /** The row the driver counts in: {@see DEEPGRAM_PERIOD} for `deepgram`, else the {@see month()}. */
    public function period(?string $driver = null): string
    {
        return self::cumulative($driver) ? self::DEEPGRAM_PERIOD : $this->month();
    }

    public function limit(?string $driver = null): int
    {
        return self::cumulative($driver)
            ? (int) config('twa.tts.deepgram.char_limit', 6_000_000)
            : (int) config('twa.tts.monthly_char_limit', 900_000);
    }

    /** True for the driver whose budget is one counter for all time (`deepgram`), false for a monthly one. */
    public static function cumulative(?string $driver = null): bool
    {
        return ($driver ?? ConfiguredSpeechSynthesizer::driver()) === ConfiguredSpeechSynthesizer::DEEPGRAM;
    }

    /**
     * Reserves `$chars` characters of the current period.
     *
     * @return string the period of the reservation (give it back to {@see refund()}: the answer may come in the next month)
     *
     * @throws SpeechSynthesisException tts_monthly_limit_reached (`tts_credit_limit_reached` for `deepgram`)
     */
    public function reserve(int $chars, ?string $driver = null): string
    {
        $period = $this->period($driver);
        $limit = $this->limit($driver);
        $chars = max(0, $chars);
        if ($limit <= 0 || $chars > $limit) {
            throw self::spent($driver);
        }

        $now = now();
        DB::table(self::TABLE)->insertOrIgnore([
            'month' => $period,
            'characters' => 0,
            'requests' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $reserved = DB::table(self::TABLE)
            ->where('month', $period)
            ->where('characters', '<=', $limit - $chars)
            ->incrementEach(['characters' => $chars, 'requests' => 1], ['updated_at' => $now]);

        if ($reserved === 0) {
            throw self::spent($driver);
        }

        return $period;
    }

    /** Gives back a reservation that the service did not spend; `$month` is the period {@see reserve()} returned. */
    public function refund(string $month, int $chars): void
    {
        $chars = max(0, $chars);

        DB::table(self::TABLE)
            ->where('month', $month)
            ->where('characters', '>=', $chars)
            ->where('requests', '>=', 1)
            ->decrementEach(['characters' => $chars, 'requests' => 1], ['updated_at' => now()]);
    }

    /** @return array{month: string, characters: int, limit: int} `month` - the period ({@see period()}) */
    public function usage(?string $driver = null): array
    {
        $period = $this->period($driver);
        $characters = DB::table(self::TABLE)->where('month', $period)->value('characters');

        return [
            'month' => $period,
            'characters' => (int) $characters,
            'limit' => $this->limit($driver),
        ];
    }

    /** Nothing more may be synthesized in this period (the nightly reconciliation queues nothing then). */
    public function exhausted(?string $driver = null): bool
    {
        $usage = $this->usage($driver);

        return $usage['limit'] <= 0 || $usage['characters'] >= $usage['limit'];
    }

    private static function spent(?string $driver): SpeechSynthesisException
    {
        return self::cumulative($driver) ? SpeechSynthesisException::creditLimitReached() : SpeechSynthesisException::monthlyLimitReached();
    }
}
