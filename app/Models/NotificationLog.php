<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    use MassPrunable;

    public $timestamps = false;

    protected $table = 'notifications_log';

    /** @var list<string> */
    protected $fillable = ['learner_id', 'type', 'payload', 'sent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Retention: rows older than `operations.notifications_log.retention_days` are deleted
     * by the scheduled `model:prune`. A reminder is only looked up within a day, so the
     * history beyond that is for analytics and support.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('operations.notifications_log.retention_days', 400));

        return static::query()->where('sent_at', '<', now()->subDays($days));
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
