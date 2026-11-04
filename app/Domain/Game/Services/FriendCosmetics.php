<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Models\Learner;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What other learners wear (docs/18 §12.8, docs/05 §5b.6): today the camel of the friends on the map and in `/friends`.
 *
 * The rule is the one of {@see Economy\WalletOverview::profile()}: only what is really in `game_profiles.equipped` is worn, nothing
 * is put in place of an empty slot, and the item is shown whatever its status is now (an archived one stays on the camel); a slot
 * without an item - or with an id that is gone - is `null`. ALL the learners are read in ONE statement (the profile joined to the
 * item by the id inside the JSON), whatever their number is. Engagement does not know `game_items`: it asks here.
 */
final class FriendCosmetics
{
    public function __construct(private readonly GameAccess $access) {}

    /**
     * The camels of the friends as the viewer may see them: `learner id => asset | null`, one entry per id; `null` for the whole answer
     * while the game is closed to the viewer (no key in the answer of `/friends`, no statement on a `game_*` table).
     *
     * @param  list<int>  $friendIds
     * @return array<int, string|null>|null
     */
    public function camelsForViewer(Learner $viewer, array $friendIds): ?array
    {
        if (! $this->access->state($viewer)->isAvailable()) {
            return null;
        }

        return $this->camelAssets($friendIds);
    }

    /**
     * Without the access check (the caller has made it): `learner id => asset | null` for every id asked.
     *
     * @param  list<int>  $learnerIds
     * @return array<int, string|null>
     */
    public function camelAssets(array $learnerIds): array
    {
        $camels = array_fill_keys($learnerIds, null);
        if ($learnerIds === []) {
            return $camels;
        }

        // `equipped.camel` (the key is ItemSlot::Camel) as a number, in the dialect of the driver; literals only, nothing of the request.
        $worn = DB::getDriverName() === 'pgsql'
            ? new Expression('CAST("game_profiles"."equipped"->>\'camel\' AS BIGINT)')
            : new Expression('CAST(json_extract("game_profiles"."equipped", \'$.camel\') AS INTEGER)');

        $rows = DB::table('game_profiles')
            ->join('game_items', function (JoinClause $join) use ($worn): void {
                $join->on($worn, '=', 'game_items.id');
            })
            ->whereIn('game_profiles.learner_id', $learnerIds)
            ->get(['game_profiles.learner_id as learner_id', 'game_items.asset as asset']);

        foreach ($rows as $row) {
            $camels[(int) $row->learner_id] = $row->asset === null ? null : (string) $row->asset;
        }

        return $camels;
    }
}
