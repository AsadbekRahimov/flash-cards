<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PollVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A chosen option of a delivery (docs/35 §1.4): unique per `(poll_delivery_id, poll_option_id)`; a change of the answer replaces
 * the delivery's rows. `poll_id` is a copy for the count of the results.
 *
 * @property int $id
 * @property int $poll_id
 * @property int $poll_delivery_id
 * @property int $poll_option_id
 * @property Carbon|null $created_at
 */
class PollVote extends Model
{
    /** @use HasFactory<PollVoteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['poll_id', 'poll_delivery_id', 'poll_option_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'poll_id' => 'integer',
            'poll_delivery_id' => 'integer',
            'poll_option_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /** @return BelongsTo<PollDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PollDelivery::class, 'poll_delivery_id');
    }

    /** @return BelongsTo<PollOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'poll_option_id');
    }
}
