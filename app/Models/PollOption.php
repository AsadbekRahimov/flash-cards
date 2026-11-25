<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PollOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An answer of a poll (docs/35 §1.4): `position` 0..9 is the `n` of its button `pl1:<token>:<n>`, unique within the poll.
 *
 * @property int $id
 * @property int $poll_id
 * @property int $position
 * @property array{ru: string, uz: string} $label at most 40 characters each
 */
class PollOption extends Model
{
    /** @use HasFactory<PollOptionFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['poll_id', 'position', 'label'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'poll_id' => 'integer',
            'position' => 'integer',
            'label' => 'array',
        ];
    }

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }
}
