<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

/**
 * GET /api/twa/league/month?category=free|pro&scope=all|region&region=…&after=… (docs/04 A.19, docs/32 §8.3, docs/35 §2.4) - see
 * {@see LeagueTableRequest}. A v1 month ignores `category` and has no pages and no tables of a region (422).
 */
final class MonthlyRatingRequest extends LeagueTableRequest {}
