<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The answer of an audio endpoint of the practice, the battle and the game for a word on R2 (docs/26 §9.9): `{"url": "https://…"}`,
 * the public address of the file, instead of its bytes. The client plays that address in an `<audio>` (CSP `media-src`): no `fetch`
 * to the bucket, so no CORS there and the token of the learner never leaves this domain. A 302 is not used on purpose: `fetch`
 * follows it to the other domain with the `Authorization` header and needs CORS on the bucket and on every copy the cache of
 * Cloudflare keeps.
 *
 * The answer belongs to the request of a learner (the clip of a round of a battle is handed out only once the round has begun):
 * `Cache-Control: private, no-store`, like the streamed clips.
 *
 * @property string $resource the public address
 */
final class AudioLocationResource extends TwaResource
{
    /** @return array{url: string} */
    public function toArray(Request $request): array
    {
        return ['url' => $this->resource];
    }

    public static function respond(string $url): JsonResponse
    {
        return (new self($url))->response()->header('Cache-Control', 'private, no-store');
    }
}
