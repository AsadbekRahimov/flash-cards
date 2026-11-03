<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\AttemptKind;
use App\Domain\Practice\Enums\Verdict;
use Carbon\CarbonImmutable;
use Database\Factories\GameRunAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The journal of accepted requests of a run (docs/18 §12.2.2): `(run_id, request_id)` is unique, so a repeat of a request finds its row and answers with the stored `result` (the body of the answer without `run`). The rows never change - there is no `updated_at`.
 *
 * @property int $id
 * @property int $run_id
 * @property int $learner_id
 * @property string $request_id
 * @property string $element_id
 * @property AttemptKind $kind
 * @property array<string, mixed> $payload
 * @property Verdict|null $verdict
 * @property bool $is_first
 * @property array<string, mixed> $result
 * @property int|null $time_spent_ms
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable|null $created_at
 */
class GameRunAttempt extends Model
{
    /** @use HasFactory<GameRunAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'learner_id',
        'request_id',
        'element_id',
        'kind',
        'payload',
        'verdict',
        'is_first',
        'result',
        'time_spent_ms',
        'accepted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'learner_id' => 'integer',
            'kind' => AttemptKind::class,
            'payload' => 'array',
            'verdict' => Verdict::class,
            'is_first' => 'boolean',
            'result' => 'array',
            'time_spent_ms' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<GameRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(GameRun::class, 'run_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
