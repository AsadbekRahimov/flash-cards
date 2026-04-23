<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['stage_id', 'number', 'title'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return HasMany<Word, $this> */
    public function words(): HasMany
    {
        return $this->hasMany(Word::class);
    }
}
