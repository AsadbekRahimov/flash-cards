<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/**
 * The kind of a duel the challenger CHOOSES (`kind` of `POST /cups/{id}/duels`, owner's decision of 27.11.2026, docs/33 §11.12):
 *
 *  - `rated` - a duel of the cup: rated when neither player has played the rated duel of the day at the moment of the acceptance (the
 *    slots decide it again there, 409 `cup_duel_rating_changed` tells the one who accepts); asked while the challenger's OWN slot of
 *    today is taken - 409 `cup_duel_rating_changed {rated: false}` and no challenge (the screen offers a friendly one); the addressee's
 *    slot taken - a friendly offer (`rated_offered = false`), as before the choice (the screen warns of it beforehand);
 *  - `friendly` - never rated: no slot, no points of the cup for either player, whatever their slots (`cup_duels.rated = false` from the
 *    challenge on). It spends the Free limit of battles of the challenger like any friendly match (К5).
 *
 * No kind (an older client) - the behaviour before the choice: rated when both slots are free, else friendly, without a refusal.
 */
enum CupDuelKind: string
{
    case Rated = 'rated';
    case Friendly = 'friendly';
}
