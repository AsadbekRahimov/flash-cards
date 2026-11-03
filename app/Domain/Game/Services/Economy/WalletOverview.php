<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Economy;

use App\Domain\Game\DTO\ProfileView;
use App\Domain\Game\DTO\WalletView;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameItem;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reads of the learner's own coins and profile (docs/18 §12.8): `GET /game/wallet`, `GET /game/profile` and the answers of the
 * buying and the equipping. Nothing here is cached (the balance, what he wears) and nothing is written; the wallet and the profile
 * are read in ONE statement (both rows are made lazily, a missing one is 0).
 */
final class WalletOverview
{
    /** The lines of the ledger the wallet shows. */
    public const LEDGER_LINES = 20;

    public function __construct(private readonly GameAccess $access) {}

    /**
     * @throws GameException game_unavailable (403)
     */
    public function wallet(Learner $learner): WalletView
    {
        $this->access->assertAvailable($learner);

        $row = DB::table('learners')
            ->leftJoin('game_wallets', 'game_wallets.learner_id', '=', 'learners.id')
            ->leftJoin('game_profiles', 'game_profiles.learner_id', '=', 'learners.id')
            ->where('learners.id', $learner->id)
            ->first(['game_wallets.balance', 'game_wallets.earned_total', 'game_wallets.spent_total', 'game_profiles.tickets']);

        $ledger = GameCoinLedgerEntry::query()
            ->where('learner_id', $learner->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LEDGER_LINES)
            ->get(['id', 'amount', 'kind', 'created_at']);

        return new WalletView(
            balance: (int) ($row->balance ?? 0),
            earnedTotal: (int) ($row->earned_total ?? 0),
            spentTotal: (int) ($row->spent_total ?? 0),
            tickets: ['balance' => (int) ($row->tickets ?? 0), 'max' => (int) config('game.tickets.max')],
            ledger: array_values($ledger->map(static fn (GameCoinLedgerEntry $entry): array => [
                'amount' => $entry->amount,
                'kind' => $entry->kind->value,
                'created_at' => CarbonImmutable::parse($entry->created_at)->utc()->toIso8601ZuluString('millisecond'),
                'title' => (string) __('game.ledger.'.$entry->kind->value),
            ])->all()),
        );
    }

    /**
     * @throws GameException game_unavailable (403)
     */
    public function profile(Learner $learner): ProfileView
    {
        $this->access->assertAvailable($learner);

        $row = DB::table('learners')
            ->leftJoin('game_wallets', 'game_wallets.learner_id', '=', 'learners.id')
            ->leftJoin('game_profiles', 'game_profiles.learner_id', '=', 'learners.id')
            ->where('learners.id', $learner->id)
            ->first(['game_wallets.balance', 'game_profiles.tickets', 'game_profiles.stops_cleared', 'game_profiles.cities_cleared', 'game_profiles.equipped']);

        $equipped = self::decodeEquipped($row->equipped ?? null);
        $worn = [];
        if ($equipped !== []) {
            // Whatever he wears is shown, whatever the item's status is now (an archived one stays on the camel).
            $items = GameItem::query()->whereIn('id', array_values($equipped))->get(['id', 'key', 'asset'])->keyBy('id');
            foreach ($equipped as $slot => $itemId) {
                $item = $items->get($itemId);
                if ($item !== null) {
                    $worn[$slot] = ['key' => $item->key, 'asset' => $item->asset];
                }
            }
        }

        return new ProfileView(
            equipped: $worn,
            stopsCleared: (int) ($row->stops_cleared ?? 0),
            citiesCleared: (int) ($row->cities_cleared ?? 0),
            coins: (int) ($row->balance ?? 0),
            tickets: (int) ($row->tickets ?? 0),
        );
    }

    /**
     * `game_profiles.equipped` as it comes out of a raw query: a JSON string `{slot: item_id}` (or nothing).
     *
     * @return array<string, int>
     */
    public static function decodeEquipped(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }

        $equipped = [];
        foreach ($decoded as $slot => $itemId) {
            if (is_int($itemId) || (is_string($itemId) && ctype_digit($itemId))) {
                $equipped[(string) $slot] = (int) $itemId;
            }
        }

        return $equipped;
    }
}
