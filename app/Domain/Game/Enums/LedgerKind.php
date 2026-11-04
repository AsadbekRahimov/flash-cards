<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_coin_ledger.kind` (docs/18 §12.2.5). Open: the arena (G3) and the seasons (G4) add their kinds with a migration of the CHECK. */
enum LedgerKind: string
{
    case RunReward = 'run_reward';
    case BossReward = 'boss_reward';
    case DailyReward = 'daily_reward';
    case ClassReward = 'class_reward';
    case BazaarHint = 'bazaar_hint';
    case ItemPurchase = 'item_purchase';
    case AdminGrant = 'admin_grant';
    case AdminRevoke = 'admin_revoke';
    case Refund = 'refund';
}
