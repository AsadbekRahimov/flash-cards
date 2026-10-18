<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Models\ProductEvent;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes append-only product analytics events (`product_events`).
 *
 * Guarantees:
 *  - never throws: a failed write is logged as a warning and swallowed, so
 *    analytics can never break the user-facing flow;
 *  - props are scalars only, capped in count / key length / string length,
 *    and keys that look like PII (names, usernames, texts, tokens) are dropped.
 *
 * Intentionally not `final` so tests can swap it with a Mockery double.
 */
class ProductEventRecorder
{
    public const MAX_PROPS = 20;

    public const MAX_KEY_LENGTH = 40;

    public const MAX_STRING_LENGTH = 100;

    /** Whole `_`-separated words in a key that mark it as PII / credentials. */
    private const FORBIDDEN_KEY_WORDS = [
        'name', 'firstname', 'lastname', 'username', 'title', 'text', 'message', 'caption',
        'email', 'phone', 'token', 'secret', 'hash', 'password', 'initdata',
    ];

    /**
     * @param  array<string, mixed>  $props  scalar values only
     */
    public function record(
        ProductEventName $name,
        array $props = [],
        ?Student $student = null,
        ?TelegramGroup $group = null,
        ?User $user = null,
    ): void {
        try {
            $attributes = [
                'name' => $name->value,
                'student_id' => $student?->id,
                'telegram_group_id' => $group !== null ? $group->id : $student?->telegram_group_id,
                'user_id' => $user?->id,
                'props' => $this->sanitize($props),
                'occurred_at' => now(),
            ];

            // Savepoint: a failed insert must not poison an outer (Postgres) transaction.
            DB::transaction(static function () use ($attributes): void {
                ProductEvent::query()->create($attributes);
            });
        } catch (\Throwable $e) {
            Log::warning('product_event.record_failed', [
                'event' => $name->value,
                'exception' => $e::class,
                'error' => Str::limit($e->getMessage(), 200),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, bool|int|float|string|null>
     */
    public function sanitize(array $props): array
    {
        $clean = [];

        foreach ($props as $key => $value) {
            if (count($clean) >= self::MAX_PROPS) {
                break;
            }

            if (preg_match('/^[a-z][a-z0-9_]{0,'.(self::MAX_KEY_LENGTH - 1).'}$/', (string) $key) !== 1) {
                continue;
            }

            if ($this->isForbiddenKey((string) $key)) {
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, self::MAX_STRING_LENGTH);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function isForbiddenKey(string $key): bool
    {
        return array_intersect(explode('_', $key), self::FORBIDDEN_KEY_WORDS) !== []
            || str_contains($key, 'init_data');
    }
}
