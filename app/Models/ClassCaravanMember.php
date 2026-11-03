<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClassCaravanMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The contribution of a learner to a class caravan (docs/18 §12.2.8): created at the first finished run of the member in the window of the caravan.
 *
 * @property int $id
 * @property int $class_caravan_id
 * @property int $learner_id
 * @property int $runs
 * @property int $stars
 * @property int $best_score
 * @property CarbonImmutable|null $boss_won_at
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ClassCaravanMember extends Model
{
    /** @use HasFactory<ClassCaravanMemberFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'class_caravan_id',
        'learner_id',
        'runs',
        'stars',
        'best_score',
        'boss_won_at',
        'last_run_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'class_caravan_id' => 'integer',
            'learner_id' => 'integer',
            'runs' => 'integer',
            'stars' => 'integer',
            'best_score' => 'integer',
            'boss_won_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClassCaravan, $this> */
    public function caravan(): BelongsTo
    {
        return $this->belongsTo(ClassCaravan::class, 'class_caravan_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
