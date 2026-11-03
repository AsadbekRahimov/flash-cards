<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The shop for a learner (docs/18 §12.8, docs/04 A.18): the cached catalogue with the learner's own state (owned, qty, equipped, locked) laid over it.
 *
 * @phpstan-type ShopItem array{key: string, kind: string, slot: string|null, effect: string|null, price: int, availability: string, owned: bool, qty: int, equipped: bool, locked_reason: 'pro'|'achievement'|null, title: string, description: string|null, asset: string|null}
 */
final readonly class ShopView
{
    /** @param  list<ShopItem>  $items */
    public function __construct(
        public array $items,
        public int $balance,
    ) {}
}
