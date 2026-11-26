<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * An outgoing message of a cup (the outbox of docs/33 §2.8, §6.4): one row per `(cup_id, dedupe_key)`, so a second "post the final"
 * finds the row and sends nothing. The sending itself is K6 (`PostCupMessageJob`).
 *
 * @property int $id
 * @property int $cup_id
 * @property string $dedupe_key
 * @property CupMessageKind $kind
 * @property int|null $chat_id
 * @property int|null $message_id
 * @property int|null $thread_id
 * @property CupMessageStatus $status
 * @property int $attempts
 * @property CarbonInterface|null $claimed_at
 * @property CarbonInterface|null $sent_at
 * @property string|null $error
 */
class CupMessage extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => CupMessageKind::class,
            'status' => CupMessageStatus::class,
            'chat_id' => 'integer',
            'message_id' => 'integer',
            'thread_id' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
