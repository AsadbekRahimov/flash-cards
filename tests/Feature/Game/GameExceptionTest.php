<?php

declare(strict_types=1);

use App\Domain\Game\Exceptions\GameException;
use App\Http\Resources\ApiErrorResource;

/*
 * docs/18 §12.8: the codes of the errors of the game, their HTTP statuses and their `details`.
 */

/** @return array<string, array{0: GameException, 1: int, 2: array<string, mixed>}> */
function gameErrorCases(): array
{
    return [
        'game_unavailable' => [GameException::unavailable(), 403, []],
        'game_limit_reached' => [GameException::limitReached(['kind' => 'runs', 'limit' => 1, 'used' => 1, 'tickets' => 0, 'resets_at' => '2026-11-05T19:00:00.000Z']), 403, ['kind' => 'runs', 'limit' => 1, 'used' => 1, 'tickets' => 0, 'resets_at' => '2026-11-05T19:00:00.000Z']],
        'game_pro_required' => [GameException::proRequired('boss', 2), 403, ['kind' => 'boss', 'stage_number' => 2]],
        'class_caravan_forbidden' => [GameException::classCaravanForbidden('centers_only'), 403, ['reason' => 'centers_only']],
        'game_run_not_found' => [GameException::runNotFound(), 404, []],
        'course_not_found' => [GameException::courseNotFound(), 404, []],
        'lesson_not_found' => [GameException::lessonNotFound(), 404, []],
        'stage_not_found' => [GameException::stageNotFound(), 404, []],
        'item_not_found' => [GameException::itemNotFound(), 404, []],
        'class_caravan_not_found' => [GameException::classCaravanNotFound(), 404, []],
        'game_run_active' => [GameException::runActive(['run_id' => 5, 'kind' => 'stop', 'progress' => ['done' => 2, 'total' => 8]]), 409, ['run_id' => 5, 'kind' => 'stop', 'progress' => ['done' => 2, 'total' => 8]]],
        'game_daily_done' => [GameException::dailyDone('2026-11-05T19:00:00.000Z'), 409, ['resets_at' => '2026-11-05T19:00:00.000Z']],
        'boss_not_ready' => [GameException::bossNotReady(5, 8, 1, 3), 409, ['introduced' => 5, 'required' => 8, 'cleared_stops' => 1, 'required_stops' => 3]],
        'stop_locked' => [GameException::stopLocked(), 409, []],
        'element_not_current' => [GameException::elementNotCurrent('eAAAAAAAAAA'), 409, ['current' => 'eAAAAAAAAAA']],
        'answer_already_accepted' => [GameException::answerAlreadyAccepted('wrong'), 409, ['verdict' => 'wrong']],
        'pair_already_matched' => [GameException::pairAlreadyMatched(), 409, []],
        'request_id_reused' => [GameException::requestIdReused(), 409, []],
        'hint_not_available' => [GameException::hintNotAvailable(), 409, []],
        'coins_insufficient' => [GameException::coinsInsufficient(3, 5), 409, ['balance' => 3, 'price' => 5]],
        'booster_limit_reached' => [GameException::boosterLimitReached(1, 1, '2026-11-09T19:00:00.000Z'), 409, ['limit' => 1, 'used' => 1, 'resets_at' => '2026-11-09T19:00:00.000Z']],
        'booster_not_owned' => [GameException::boosterNotOwned(), 409, []],
        'item_not_purchasable' => [GameException::itemNotPurchasable('achievement'), 409, ['reason' => 'achievement']],
        'item_already_owned' => [GameException::itemAlreadyOwned(), 409, []],
        'class_caravan_active' => [GameException::classCaravanActive(), 409, []],
        'class_boss_done' => [GameException::classBossDone(), 409, []],
        'class_boss_locked' => [GameException::classBossLocked(), 409, []],
        'game_run_closed' => [GameException::runClosed('expired'), 410, ['finish_reason' => 'expired']],
        'game_not_enough_material' => [GameException::notEnoughMaterial(4, 6, 'few_words'), 422, ['available' => 4, 'required' => 6, 'reason' => 'few_words']],
        'invalid_answer_shape' => [GameException::invalidAnswerShape(), 422, []],
        'unknown_element_id' => [GameException::unknownElementId(), 422, []],
        'unknown_option_id' => [GameException::unknownOptionId(), 422, []],
        'lesson_not_in_course' => [GameException::lessonNotInCourse(), 422, []],
        'stage_not_in_course' => [GameException::stageNotInCourse(), 422, []],
    ];
}

it('carries the code, the HTTP status and the details of docs/18 §12.8', function (string $code, GameException $e, int $status, array $details): void {
    expect($e->errorCode)->toBe($code)
        ->and($e->httpStatus())->toBe($status)
        ->and($e->details)->toBe($details)
        ->and($e->getMessage())->not->toBe('');
})->with(function (): Generator {
    foreach (gameErrorCases() as $code => [$e, $status, $details]) {
        yield $code => [$code, $e, $status, $details];
    }
});

it('covers every code constant of the exception', function (): void {
    $constants = array_values(array_filter(
        (new ReflectionClass(GameException::class))->getConstants(),
        static fn (string $value, string $name): bool => ! str_starts_with($name, 'KIND_') && ! str_starts_with($name, 'REASON_'),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect(array_keys(gameErrorCases()))->toEqualCanonicalizing($constants);
});

it('goes into the envelope of the API the way a controller answers it', function (): void {
    $e = GameException::coinsInsufficient(3, 5);
    $response = ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);

    expect($response->getStatusCode())->toBe(409)
        ->and(json_decode((string) $response->getContent(), true))
        ->toMatchArray(['error' => ['code' => 'coins_insufficient', 'message' => 'Not enough coins.', 'details' => ['balance' => 3, 'price' => 5]]]);
});
