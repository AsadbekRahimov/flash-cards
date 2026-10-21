<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/** Internal to LeagueService::place: a parallel placement of the same learner won; the transaction is rolled back. */
final class LeagueAlreadyPlaced extends RuntimeException {}
