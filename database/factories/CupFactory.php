<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cup\Enums\CupStatus;
use App\Models\Course;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A cup as a row (docs/33 §2.2): by default a DRAFT of the default sizes. `scheduled()` / `running()` set the borders; the rows of the
 * days and the plan of words are made by `CupLifecycleService::start`, not here.
 *
 * @extends Factory<Cup>
 */
class CupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory()->pending(),
            'active_group_id' => fn (array $attributes): ?int => self::isOpen($attributes['status']) ? (int) $attributes['telegram_group_id'] : null,
            'public_code' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'status' => CupStatus::Draft,
            'course_id' => Course::factory()->published(),
            'level' => 'A2',
            'timezone' => 'Asia/Tashkent',
            'days' => 7,
            'counted_days' => 5,
            'round_tasks' => 10,
            'duel_tasks' => 5,
            'summaries_enabled' => true,
            'summary_minute' => 1200,
            'locale' => 'ru',
            'seed_secret' => bin2hex(random_bytes(32)),
            'proposed_by_telegram_user_id' => fake()->numberBetween(100_000, 999_999_999),
            'meta' => [],
        ];
    }

    public function scheduled(?string $startsAt = null): static
    {
        return $this->state(function () use ($startsAt): array {
            $starts = $startsAt !== null ? now()->parse($startsAt) : now()->addDay()->startOfDay();

            return ['status' => CupStatus::Scheduled, 'starts_at' => $starts, 'ends_at' => $starts->copy()->addDays(7), 'confirmed_at' => now()];
        });
    }

    private static function isOpen(mixed $status): bool
    {
        $status = $status instanceof CupStatus ? $status : CupStatus::tryFrom((string) $status);

        return $status?->isOpen() === true;
    }
}
