<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Telegram\Services\BotButtons;

/**
 * The one list of the Mini App screens a message of the bot leads to (docs/36 §3б): the path of the TWA router, `{name}` for a
 * parameter. The address is built by {@see BotButtons::twaUrl()} only - nobody else glues `twa.base_url` and a path together.
 * A new screen for a button is a new case here (and a route in `resources/twa/src/router/index.ts`).
 */
enum TwaScreen: string
{
    case Today = '/';
    case Courses = '/courses';
    case Course = '/courses/{slug}';
    case League = '/league';
    case LeagueMonth = '/league/month';
    case Friends = '/friends';
    case Battle = '/battles/{id}';
    case Training = '/training/{id}';
    case Exam = '/exam/{id}';
    case GameClass = '/game/class/{id}';
    case Referrals = '/referrals';
    case Billing = '/billing';
    case Achievements = '/achievements';
}
