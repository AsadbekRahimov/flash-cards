<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A date with its milliseconds. The default `datetime` cast writes `Y-m-d H:i:s` and drops them; a clock that moves by 2 s per mistake
 * and takes an answer 1.5 s late (`practice_sessions.deadline_at`, docs/16 §5.5a) needs them. The column is `timestamp(3)`.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|null>
 */
final class MillisecondDateTime implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->format('Y-m-d H:i:s.v');
    }
}
