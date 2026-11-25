<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One accepted answer of a round of a cup (docs/33 §2.6): one per task, `result` is the body answered to the client - a repeated
 * `request_id` gets it again, byte for byte.
 *
 * @property int $id
 * @property int $attempt_id
 * @property string $element_id
 * @property string $request_id
 * @property array<string, mixed> $payload
 * @property string $verdict
 * @property int $points
 * @property array<string, mixed> $result
 * @property CarbonInterface $accepted_at
 * @property CarbonInterface|null $created_at
 */
class CupRoundAnswer extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'points' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }
}
