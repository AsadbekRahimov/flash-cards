<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\AchievementKey;
use Database\Factories\AchievementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An unlocked achievement of a learner (docs/13 §1.5; unique per `(learner_id, key)`). `shared_at`
 * is set once, when the learner shares it from the TWA.
 *
 * @property int $id
 * @property int $learner_id
 * @property string $key
 * @property Carbon $unlocked_at
 * @property Carbon|null $shared_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $learner
 */
class Achievement extends Model
{
    /** @use HasFactory<AchievementFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['learner_id', 'key', 'unlocked_at', 'shared_at', 'meta'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'unlocked_at' => 'datetime',
            'shared_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function keyEnum(): ?AchievementKey
    {
        return AchievementKey::tryFrom($this->key);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
