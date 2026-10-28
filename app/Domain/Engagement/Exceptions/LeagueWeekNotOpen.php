<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/**
 * Internal to LeagueService::place: the week is `closing` / `closed` by the time the seat is taken (a request that
 * started before Monday 00:00 in the league zone); the placement is rolled back and nobody is seated.
 */
final class LeagueWeekNotOpen extends RuntimeException {}
