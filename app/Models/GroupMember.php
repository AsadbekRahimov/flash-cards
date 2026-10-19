<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GroupMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $telegram_group_id
 * @property int $learner_id
 * @property string $status
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 */
class GroupMember extends Model
{
    /** @use HasFactory<GroupMemberFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'telegram_group_id',
        'learner_id',
        'status',
        'joined_at',
        'left_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TelegramGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
