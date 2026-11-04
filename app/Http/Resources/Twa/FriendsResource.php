<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\FriendRequestEntry;
use App\Domain\Engagement\DTO\FriendsOverview;
use Illuminate\Http\Request;

/**
 * GET /api/twa/friends (and the answer of accept / decline / remove): the learner's friends and the open
 * requests (docs/04 A.13). `id` is `friendships.id` — what the actions and `POST /challenges` take. Of other
 * people only a first name, the streak and the number of learned words leave the server — no ids,
 * usernames or time zones. `camel_asset` (the camel he wears, G2.6) is there only while the game is open to the viewer.
 *
 * @property FriendsOverview $resource
 */
final class FriendsResource extends TwaResource
{
    /**
     * @param  array<int, string|null>|null  $camels  learner id => the asset of the camel he wears; `null` while the game is closed
     *                                                to the viewer (then the key `camel_asset` is not in the payload)
     */
    public function __construct(FriendsOverview $resource, private readonly ?array $camels = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{invite_link: string|null, limit: int, friends: list<array{id: int, first_name: string|null, streak: int, words_learned: int, since: string|null, camel_asset?: string|null}>, incoming: list<array{id: int, first_name: string|null, requested_at: string|null}>, outgoing: list<array{id: int, first_name: string|null, requested_at: string|null}>}
     */
    public function toArray(Request $request): array
    {
        $friends = [];
        foreach ($this->resource->friends as $entry) {
            $row = [
                'id' => $entry->friendshipId,
                'first_name' => $entry->firstName,
                'streak' => $entry->streak,
                'words_learned' => $entry->wordsLearned,
                'since' => $entry->since?->toIso8601String(),
            ];
            if ($this->camels !== null) {
                $row['camel_asset'] = $this->camels[$entry->learnerId] ?? null;
            }
            $friends[] = $row;
        }

        return [
            'invite_link' => $this->resource->inviteLink,
            'limit' => $this->resource->limit,
            'friends' => $friends,
            'incoming' => self::requests($this->resource->incoming),
            'outgoing' => self::requests($this->resource->outgoing),
        ];
    }

    /**
     * @param  list<FriendRequestEntry>  $entries
     * @return list<array{id: int, first_name: string|null, requested_at: string|null}>
     */
    private static function requests(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                'id' => $entry->friendshipId,
                'first_name' => $entry->firstName,
                'requested_at' => $entry->requestedAt?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
