<?php

declare(strict_types=1);

namespace App\Domain\Game\Exceptions;

use App\Domain\Practice\Exceptions\PracticeException;
use RuntimeException;

/**
 * Why a game action was refused (docs/18 §12.8). Same shape as the other domain exceptions ({@see PracticeException}):
 * `errorCode` is the stable machine-readable code, `httpStatus()` the status of the TWA answer, `details` the object that goes into
 * `error.details` of the envelope (empty for the codes that have none). A controller answers
 * `ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details)`.
 *
 *   403  game_unavailable (the game is off / the learner is not in the pilot: one code, no hint) · game_limit_reached {kind, limit, used, tickets, resets_at}
 *        · game_pro_required {kind, stage_number} · class_caravan_forbidden {reason: not_teacher | group_inactive | centers_only}
 *   404  game_run_not_found (a stranger's run looks the same) · course_not_found · lesson_not_found · stage_not_found · item_not_found · class_caravan_not_found
 *   409  game_run_active {run_id, kind, progress} · game_daily_done {resets_at} · boss_not_ready {introduced, required, cleared_stops, required_stops}
 *        · stop_locked · element_not_current {current} · answer_already_accepted {verdict} · pair_already_matched · request_id_reused · hint_not_available
 *        · coins_insufficient {balance, price} · booster_limit_reached {limit, used, resets_at} · booster_not_owned · booster_already_used · booster_not_applicable · item_not_purchasable {reason}
 *        · item_already_owned · class_caravan_active · class_boss_done · class_boss_locked
 *   410  game_run_closed {finish_reason}
 *   422  game_not_enough_material {available, required, reason} · invalid_answer_shape · unknown_element_id · unknown_option_id · lesson_not_in_course · stage_not_in_course
 */
final class GameException extends RuntimeException
{
    public const UNAVAILABLE = 'game_unavailable';

    public const LIMIT_REACHED = 'game_limit_reached';

    public const PRO_REQUIRED = 'game_pro_required';

    public const CLASS_CARAVAN_FORBIDDEN = 'class_caravan_forbidden';

    public const RUN_NOT_FOUND = 'game_run_not_found';

    public const COURSE_NOT_FOUND = 'course_not_found';

    public const LESSON_NOT_FOUND = 'lesson_not_found';

    public const STAGE_NOT_FOUND = 'stage_not_found';

    public const ITEM_NOT_FOUND = 'item_not_found';

    public const CLASS_CARAVAN_NOT_FOUND = 'class_caravan_not_found';

    public const RUN_ACTIVE = 'game_run_active';

    public const DAILY_DONE = 'game_daily_done';

    public const BOSS_NOT_READY = 'boss_not_ready';

    public const STOP_LOCKED = 'stop_locked';

    public const ELEMENT_NOT_CURRENT = 'element_not_current';

    public const ANSWER_ALREADY_ACCEPTED = 'answer_already_accepted';

    public const PAIR_ALREADY_MATCHED = 'pair_already_matched';

    public const REQUEST_ID_REUSED = 'request_id_reused';

    public const HINT_NOT_AVAILABLE = 'hint_not_available';

    public const COINS_INSUFFICIENT = 'coins_insufficient';

    public const BOOSTER_LIMIT_REACHED = 'booster_limit_reached';

    public const BOOSTER_NOT_OWNED = 'booster_not_owned';

    public const BOOSTER_ALREADY_USED = 'booster_already_used';

    public const BOOSTER_NOT_APPLICABLE = 'booster_not_applicable';

    public const ITEM_NOT_PURCHASABLE = 'item_not_purchasable';

    public const ITEM_ALREADY_OWNED = 'item_already_owned';

    public const CLASS_CARAVAN_ACTIVE = 'class_caravan_active';

    public const CLASS_BOSS_DONE = 'class_boss_done';

    public const CLASS_BOSS_LOCKED = 'class_boss_locked';

    public const RUN_CLOSED = 'game_run_closed';

    public const NOT_ENOUGH_MATERIAL = 'game_not_enough_material';

    public const INVALID_ANSWER_SHAPE = 'invalid_answer_shape';

    public const UNKNOWN_ELEMENT_ID = 'unknown_element_id';

    public const UNKNOWN_OPTION_ID = 'unknown_option_id';

    public const LESSON_NOT_IN_COURSE = 'lesson_not_in_course';

    public const STAGE_NOT_IN_COURSE = 'stage_not_in_course';

    /** `details.kind` of `game_limit_reached` / `game_pro_required`, `details.reason` of `item_not_purchasable`. */
    public const KIND_RUNS = 'runs';

    public const KIND_BOSS = 'boss';

    public const KIND_BOOSTERS = 'boosters';

    /** `details.reason` of `class_caravan_forbidden` (docs/18 §12.11). */
    public const REASON_NOT_TEACHER = 'not_teacher';

    public const REASON_GROUP_INACTIVE = 'group_inactive';

    public const REASON_CENTERS_ONLY = 'centers_only';

    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'The game is not available.');
    }

    /** @param array{kind: string, limit: int, used: int, tickets: int, resets_at: string} $details */
    public static function limitReached(array $details): self
    {
        return new self(self::LIMIT_REACHED, 'The limit of the game is used up.', $details);
    }

    public static function proRequired(string $kind, ?int $stageNumber = null): self
    {
        return new self(self::PRO_REQUIRED, 'This part of the game is a Pro feature.', ['kind' => $kind, 'stage_number' => $stageNumber]);
    }

    /** @param self::REASON_* $reason */
    public static function classCaravanForbidden(string $reason): self
    {
        return new self(self::CLASS_CARAVAN_FORBIDDEN, 'The caravan of the class cannot be run by this user or in this group.', ['reason' => $reason]);
    }

    public static function runNotFound(): self
    {
        return new self(self::RUN_NOT_FOUND, 'No such run.');
    }

    public static function courseNotFound(): self
    {
        return new self(self::COURSE_NOT_FOUND, 'No such course.');
    }

    public static function lessonNotFound(): self
    {
        return new self(self::LESSON_NOT_FOUND, 'No such lesson.');
    }

    public static function stageNotFound(): self
    {
        return new self(self::STAGE_NOT_FOUND, 'No such stage.');
    }

    public static function itemNotFound(): self
    {
        return new self(self::ITEM_NOT_FOUND, 'No such item.');
    }

    public static function classCaravanNotFound(): self
    {
        return new self(self::CLASS_CARAVAN_NOT_FOUND, 'No such class caravan.');
    }

    /** @param array{run_id: int, kind: string, progress: array<string, mixed>} $details */
    public static function runActive(array $details): self
    {
        return new self(self::RUN_ACTIVE, 'There is an unfinished run.', $details);
    }

    public static function dailyDone(string $resetsAt): self
    {
        return new self(self::DAILY_DONE, 'The daily caravan is done.', ['resets_at' => $resetsAt]);
    }

    public static function bossNotReady(int $introduced, int $required, int $clearedStops, int $requiredStops): self
    {
        return new self(self::BOSS_NOT_READY, 'The boss of this city is not ready.', [
            'introduced' => $introduced,
            'required' => $required,
            'cleared_stops' => $clearedStops,
            'required_stops' => $requiredStops,
        ]);
    }

    public static function stopLocked(): self
    {
        return new self(self::STOP_LOCKED, 'The lesson has not been started in the cards.');
    }

    public static function elementNotCurrent(string $current): self
    {
        return new self(self::ELEMENT_NOT_CURRENT, 'This is not the current element.', ['current' => $current]);
    }

    public static function answerAlreadyAccepted(string $verdict): self
    {
        return new self(self::ANSWER_ALREADY_ACCEPTED, 'The element has its final answer.', ['verdict' => $verdict]);
    }

    public static function pairAlreadyMatched(): self
    {
        return new self(self::PAIR_ALREADY_MATCHED, 'The pair is already matched.');
    }

    public static function requestIdReused(): self
    {
        return new self(self::REQUEST_ID_REUSED, 'The request id was used for a request of another kind.');
    }

    public static function hintNotAvailable(): self
    {
        return new self(self::HINT_NOT_AVAILABLE, 'No hint is available now.');
    }

    public static function coinsInsufficient(int $balance, int $price): self
    {
        return new self(self::COINS_INSUFFICIENT, 'Not enough coins.', ['balance' => $balance, 'price' => $price]);
    }

    public static function boosterLimitReached(int $limit, int $used, string $resetsAt): self
    {
        return new self(self::BOOSTER_LIMIT_REACHED, 'The limit of boosters of the week is used up.', ['limit' => $limit, 'used' => $used, 'resets_at' => $resetsAt]);
    }

    public static function boosterNotOwned(): self
    {
        return new self(self::BOOSTER_NOT_OWNED, 'The learner does not own this booster.');
    }

    /** A booster of this kind is already on the element (`hint`) or in the run (`extra_flask`, `second_chance`). */
    public static function boosterAlreadyUsed(): self
    {
        return new self(self::BOOSTER_ALREADY_USED, 'This booster is already used here.');
    }

    /** The booster has nothing to do in this run (a flask or a second chance in a run without flasks). */
    public static function boosterNotApplicable(): self
    {
        return new self(self::BOOSTER_NOT_APPLICABLE, 'This booster does nothing in this run.');
    }

    public static function itemNotPurchasable(string $reason): self
    {
        return new self(self::ITEM_NOT_PURCHASABLE, 'The item cannot be bought.', ['reason' => $reason]);
    }

    public static function itemAlreadyOwned(): self
    {
        return new self(self::ITEM_ALREADY_OWNED, 'The learner already owns this item.');
    }

    public static function classCaravanActive(): self
    {
        return new self(self::CLASS_CARAVAN_ACTIVE, 'The group already has an active caravan.');
    }

    public static function classBossDone(): self
    {
        return new self(self::CLASS_BOSS_DONE, 'The boss of the class has been fought already.');
    }

    public static function classBossLocked(): self
    {
        return new self(self::CLASS_BOSS_LOCKED, 'The boss of the class is locked.');
    }

    public static function runClosed(string $finishReason): self
    {
        return new self(self::RUN_CLOSED, 'The run is closed.', ['finish_reason' => $finishReason]);
    }

    public static function notEnoughMaterial(int $available, int $required, string $reason): self
    {
        return new self(self::NOT_ENOUGH_MATERIAL, "Not enough material for a run: {$available} of {$required}.", ['available' => $available, 'required' => $required, 'reason' => $reason]);
    }

    public static function invalidAnswerShape(): self
    {
        return new self(self::INVALID_ANSWER_SHAPE, 'The answer has the wrong shape.');
    }

    public static function unknownElementId(): self
    {
        return new self(self::UNKNOWN_ELEMENT_ID, 'No such element in the run.');
    }

    public static function unknownOptionId(): self
    {
        return new self(self::UNKNOWN_OPTION_ID, 'No such option in the element.');
    }

    public static function lessonNotInCourse(): self
    {
        return new self(self::LESSON_NOT_IN_COURSE, 'The lesson is not in the course.');
    }

    public static function stageNotInCourse(): self
    {
        return new self(self::STAGE_NOT_IN_COURSE, 'The stage is not in the course.');
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::UNAVAILABLE, self::LIMIT_REACHED, self::PRO_REQUIRED, self::CLASS_CARAVAN_FORBIDDEN => 403,
            self::RUN_NOT_FOUND, self::COURSE_NOT_FOUND, self::LESSON_NOT_FOUND, self::STAGE_NOT_FOUND, self::ITEM_NOT_FOUND, self::CLASS_CARAVAN_NOT_FOUND => 404,
            self::RUN_CLOSED => 410,
            self::NOT_ENOUGH_MATERIAL, self::INVALID_ANSWER_SHAPE, self::UNKNOWN_ELEMENT_ID, self::UNKNOWN_OPTION_ID, self::LESSON_NOT_IN_COURSE, self::STAGE_NOT_IN_COURSE => 422,
            default => 409,
        };
    }
}
