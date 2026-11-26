<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

/** GET /api/twa/league/all-time?category=free|pro&scope=all|region&region=…&after=… (docs/04 A.19a, docs/32 §8.3) - see {@see LeagueTableRequest}. */
final class AllTimeRatingRequest extends LeagueTableRequest {}
