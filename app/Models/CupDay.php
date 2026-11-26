<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CupDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day of a running cup (docs/33 §2.4): its borders in UTC (midnights of the cup's timezone) and the snapshot of its round - the same
 * tasks for every participant, in every supported locale, written once. Server only.
 *
 * @property int $id
 * @property int $cup_id
 * @property int $day
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property array<string, mixed>|null $snapshot
 * @property CarbonInterface|null $built_at
 * @property CarbonInterface|null $summary_posted_at
 * @property-read Cup $cup
 */
class CupDay extends Model
{
    /** @use HasFactory<CupDayFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['snapshot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'snapshot' => 'array',
            'built_at' => 'datetime',
            'summary_posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cup, $this> */
    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }
}
