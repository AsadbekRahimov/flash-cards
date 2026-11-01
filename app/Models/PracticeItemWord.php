<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PracticeItemWordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A target word of a task (docs/16 §2.4): an active word of the same course. The link belongs to the task, not to a
 * version - a change of the target words is an ordinary edit, and the table is rewritten with it.
 *
 * @property int $id
 * @property int $item_id
 * @property int $word_id
 */
class PracticeItemWord extends Model
{
    /** @use HasFactory<PracticeItemWordFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['item_id', 'word_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'word_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PracticeItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PracticeItem::class, 'item_id');
    }

    /** @return BelongsTo<Word, $this> */
    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
