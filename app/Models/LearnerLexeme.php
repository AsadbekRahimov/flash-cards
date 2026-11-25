<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Database\Factories\LearnerLexemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The state "learner × lexeme" of the word points (docs/32 §2.2): one attempt a rating day, the first confirmation of a new word,
 * the scheduled due already paid for, the first long repetitions. Primary key `(learner_id, lexeme_id)`; written under
 * `FOR UPDATE` by the scoring core only (S4). The dates are rating days of the league zone.
 *
 * @property int $learner_id
 * @property int $lexeme_id
 * @property Carbon|null $confirmed_on
 * @property string|null $confirmed_source
 * @property Carbon|null $last_attempt_on
 * @property Carbon|null $last_credit_due_at
 * @property Carbon|null $first_long_on
 * @property Carbon|null $long_free_on
 * @property Carbon|null $long_pro_on
 */
class LearnerLexeme extends Model
{
    use HasCompositeKey;

    /** @use HasFactory<LearnerLexemeFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'learner_id';

    /** @var list<string> */
    protected $fillable = [
        'learner_id', 'lexeme_id', 'confirmed_on', 'confirmed_source', 'last_attempt_on', 'last_credit_due_at',
        'first_long_on', 'long_free_on', 'long_pro_on',
    ];

    public function compositeKey(): array
    {
        return ['learner_id', 'lexeme_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'lexeme_id' => 'integer',
            'confirmed_on' => 'date',
            'last_attempt_on' => 'date',
            'last_credit_due_at' => 'datetime',
            'first_long_on' => 'date',
            'long_free_on' => 'date',
            'long_pro_on' => 'date',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Lexeme, $this> */
    public function lexeme(): BelongsTo
    {
        return $this->belongsTo(Lexeme::class);
    }
}
