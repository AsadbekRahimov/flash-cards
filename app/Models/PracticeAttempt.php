<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\Verdict;
use Carbon\CarbonImmutable;
use Database\Factories\PracticeAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The journal of accepted requests of a solo session (docs/16 §2.6): `(session_id, request_id)` is unique, so a repeat
 * of a request finds its row and answers with the stored `result` (the body of the answer without `session`). The rows
 * never change - there is no `updated_at`.
 *
 * @property int $id
 * @property int $session_id
 * @property int $learner_id
 * @property string $request_id
 * @property string $element_id
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property Verdict|null $verdict
 * @property bool $is_first
 * @property array<string, mixed> $result
 * @property int|null $time_spent_ms
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable|null $created_at
 */
class PracticeAttempt extends Model
{
    /** @use HasFactory<PracticeAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** A final answer to a whole element (collocation, sentence, dictation). */
    public const KIND_ANSWER = 'answer';

    /** One matching of a round of pairs. */
    public const KIND_PAIR = 'pair';

    /** "I don't know". */
    public const KIND_SKIP = 'skip';

    /** A swap of the element for a spare (docs/16 §10.4). */
    public const KIND_REPLACE = 'replace';

    /** @var list<string> */
    protected $fillable = [
        'session_id',
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
            'session_id' => 'integer',
            'learner_id' => 'integer',
            'payload' => 'array',
            'verdict' => Verdict::class,
            'is_first' => 'boolean',
            'result' => 'array',
            'time_spent_ms' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PracticeSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PracticeSession::class, 'session_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
