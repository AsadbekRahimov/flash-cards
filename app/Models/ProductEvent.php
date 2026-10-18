<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only product analytics event. Written via ProductEventRecorder only.
 *
 * @property int $id
 * @property string $name
 * @property int|null $student_id
 * @property int|null $telegram_group_id
 * @property int|null $user_id
 * @property array<string, bool|int|float|string|null> $props
 * @property Carbon $occurred_at
 */
class ProductEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['name', 'student_id', 'telegram_group_id', 'user_id', 'props', 'occurred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'props' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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
