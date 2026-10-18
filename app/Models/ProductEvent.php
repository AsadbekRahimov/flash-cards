<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only product analytics event. Written via ProductEventRecorder only.
 *
 * @property int $id
 * @property string $name
 * @property int|null $learner_id
 * @property int|null $telegram_group_id
 * @property int|null $user_id
 * @property array<string, bool|int|float|string|null> $props
 * @property CarbonImmutable $occurred_at
 */
class ProductEvent extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['name', 'learner_id', 'telegram_group_id', 'user_id', 'props', 'occurred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'props' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * Retention: events older than `operations.product_events.retention_days`
     * are deleted by the scheduled `model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('operations.product_events.retention_days', 400));

        return static::query()->where('occurred_at', '<', now()->subDays($days));
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<TelegramGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
