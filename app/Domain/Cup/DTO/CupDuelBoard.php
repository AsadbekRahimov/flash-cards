<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Domain\Cup\Services\CupDuelReader;
use Carbon\CarbonImmutable;

/**
 * The duels of a cup for one participant (`GET /cups/{id}/duels`, docs/04 A.23d, D2) - all read by ONE statement
 * ({@see CupDuelReader::board()}): the open challenges to me, the open challenges to anybody (not mine), my own open challenge, my duels
 * of today that are not open any more; and whether I may challenge now ({@see CupDuelReader::availability()}).
 *
 * @phpstan-import-type Availability from CupDuelReader
 */
final readonly class CupDuelBoard
{
    /**
     * @param  list<CupDuelView>  $incoming
     * @param  list<CupDuelView>  $open
     * @param  list<CupDuelView>  $today
     * @param  Availability|null  $availability
     */
    public function __construct(
        public array $incoming,
        public array $open,
        public ?CupDuelView $mine,
        public array $today,
        public ?array $availability,
        public CarbonImmutable $now,
    ) {}
}
