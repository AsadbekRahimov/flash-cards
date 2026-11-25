<?php

declare(strict_types=1);

namespace App\Domain\Polls\Enums;

use App\Domain\Polls\Services\PollVoteService;

/**
 * The verdict of one tap on a poll button (docs/35 §1.5 "Голос", {@see PollVoteService::tap()}).
 * O5 (PollCallbackHandler) turns it into the answer of the callback and the edit of the message:
 *  - `Voted` - the answer is recorded (first time or changed, see `changed`): the message becomes "Спасибо" (+ "Изменить ответ" when
 *    allowed);
 *  - `Toggled` - several answers: an option was ticked / unticked in the draft; only the keyboard changes (☐/☑);
 *  - `Repeated` - the same answer again (a lost edit, a double tap): nothing written, show the state after the answer again;
 *  - `Changing` - "Изменить ответ": the keyboard of the options comes back with the current answer ticked;
 *  - `EmptySubmit` - "Отправить" with nothing ticked: a toast "Выберите хотя бы один вариант";
 *  - `LimitReached` - ticking one more than `max_choices`: a toast, nothing written;
 *  - `Locked` - already answered and the poll does not allow a change: a toast, show the state after the answer;
 *  - `Closed` - the poll is closed, stopped or past `closes_at`: an alert "Опрос завершён", the keyboard goes;
 *  - `Unavailable` - unknown / malformed token, a tap by someone else than the recipient, an option that does not exist, a message
 *    that never went out: an alert "Опрос недоступен". The same verdict for every such case - no oracle about other deliveries.
 */
enum PollTapStatus: string
{
    case Voted = 'voted';
    case Toggled = 'toggled';
    case Repeated = 'repeated';
    case Changing = 'changing';
    case EmptySubmit = 'empty_submit';
    case LimitReached = 'limit_reached';
    case Locked = 'locked';
    case Closed = 'closed';
    case Unavailable = 'unavailable';
}
