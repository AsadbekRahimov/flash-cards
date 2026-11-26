<?php

declare(strict_types=1);

namespace App\Domain\Cup\Support;

/**
 * Every random choice of a cup is a function of `cups.seed_secret` (32 random bytes, hex, server only - docs/33 §5.1): the plan of
 * the words, the snapshot of each day, the order of the tasks and of the options of each participant. Derived by HMAC-SHA256 so that
 * nothing can be computed from `cup_id` and the day: a participant cannot know tomorrow's words, and the order of another participant
 * stays unknown to him even with the same words.
 */
final class CupSeeds
{
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** 128 bits, base64url without padding: 22 characters (`cups.public_code`). */
    public static function newPublicCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /** The seed of the plan of the words of the whole cup. */
    public static function plan(string $secret): string
    {
        return hash_hmac('sha256', 'plan', $secret, true);
    }

    /** The seed of day `$day`: its snapshot, and the root of the orders of the participants on that day. */
    public static function day(string $secret, int $day): string
    {
        return hash_hmac('sha256', "day:{$day}", $secret, true);
    }

    /** The order of the tasks of a participant on a day. */
    public static function order(string $daySeed, int $participantId): string
    {
        return hash_hmac('sha256', "order:{$participantId}", $daySeed, true);
    }

    /** The order of the options of one task for a participant on a day. */
    public static function options(string $daySeed, int $participantId, string $elementId): string
    {
        return hash_hmac('sha256', "options:{$participantId}:{$elementId}", $daySeed, true);
    }
}
