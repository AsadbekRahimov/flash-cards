<?php

declare(strict_types=1);

/*
 * docs/35 O5, docs/04 B.5b: a tap on a poll button in the bot. Every verdict of PollVoteService::tap() turns into one answer of the
 * query and at most one edit of the message (caption of a photo, text of a message), on the language of the delivery; after an
 * answer the learner sees "Спасибо" only (docs/31 §5г). Refusals - a group, a forwarded copy, someone else's tap, a forged or
 * malformed button, a stripped message - get the alert «Опрос недоступен» and change nothing. The whole update costs the statements
 * of tap() plus the dispatcher's one and the event: the texts come from the view cache.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Services\PollViewCache;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\ProductEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Polls\PollFixtures;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/**
 * @param  array<string, mixed>  $message  merged into the message of the tap
 * @param  array<string, mixed>  $from  merged into the sender
 * @return array<string, mixed>
 */
function pollTapUpdate(PollDelivery $delivery, string $action, array $message = [], array $from = [], ?string $data = null): array
{
    return [
        'update_id' => random_int(1, 1_000_000),
        'callback_query' => [
            'id' => 'cb-'.random_int(1, 1_000_000_000),
            'from' => ['id' => $delivery->telegram_user_id, 'is_bot' => false, 'first_name' => 'Ann', ...$from],
            'message' => ['message_id' => 4242, 'date' => 1_760_000_000, 'chat' => ['id' => $delivery->telegram_user_id, 'type' => 'private'], 'text' => 'Вопрос', ...$message],
            'data' => $data ?? "pl1:{$delivery->token}:{$action}",
        ],
    ];
}

/** @param array<string, mixed> $update */
function pollDispatch(array $update): void
{
    app(TelegramDispatcher::class)->dispatch($update);
}

function pollOption(PollDelivery $delivery, int $position): int
{
    return (int) PollOption::query()->where('poll_id', $delivery->poll_id)->where('position', $position)->value('id');
}

/** @return list<int> */
function pollVotesFor(PollDelivery $delivery): array
{
    return PollVote::query()->where('poll_delivery_id', $delivery->id)->orderBy('poll_option_id')->pluck('poll_option_id')->map(fn ($id): int => (int) $id)->all();
}

/** @return list<string> callback_data of every button of a markup */
function pollButtons(?array $markup): array
{
    $out = [];
    foreach ($markup['inline_keyboard'] ?? [] as $row) {
        foreach ($row as $button) {
            $out[] = $button['text'].'|'.($button['callback_data'] ?? '');
        }
    }

    return $out;
}

// --- one answer ----------------------------------------------------------------------------------------------------------

it('records the answer and turns the text into the question and "Спасибо" with «Изменить ответ»', function (): void {
    $delivery = PollFixtures::delivered();
    $update = pollTapUpdate($delivery, '1');

    pollDispatch($update);

    $edit = $this->tg->lastTextEdit();
    expect(pollVotesFor($delivery))->toBe([pollOption($delivery, 1)])
        ->and($edit['chat_id'])->toBe($delivery->telegram_user_id)
        ->and($edit['message_id'])->toBe(4242)
        ->and($edit['parse_mode'])->toBeNull()
        ->and($edit['text'])->toBe("Какой формат занятий вам удобнее?\n\n✅ Спасибо! Ответ принят.")
        ->and($edit['text'])->not->toContain('Вариант')
        ->and(pollButtons($edit['markup']))->toBe(["✏️ Изменить ответ|pl1:{$delivery->token}:c"])
        ->and($this->tg->answered)->toBe([$update['callback_query']['id']])
        ->and($this->tg->answeredTexts)->toBe([])
        ->and($this->tg->captionEdits)->toBe([]);

    $event = ProductEvent::query()->where('name', ProductEventName::PollAnswered->value)->sole();
    expect($event->props)->toEqual(['poll_id' => $delivery->poll_id, 'delivery_id' => $delivery->id, 'changed' => false]);
});

it('edits the caption of a photo in Uzbek and drops the buttons when the answer cannot be changed', function (): void {
    $delivery = PollFixtures::delivered(['allow_change' => false], ['locale' => 'uz']);

    pollDispatch(pollTapUpdate($delivery, '0', ['photo' => [['file_id' => 'x', 'width' => 90, 'height' => 90]], 'caption' => 'Savol']));

    expect($this->tg->textEdits)->toBe([])
        ->and($this->tg->captionEdits)->toHaveCount(1)
        ->and($this->tg->captionEdits[0]['caption'])->toBe("Qaysi dars formati sizga qulayroq?\n\n✅ Rahmat! Javobingiz qabul qilindi.")
        ->and($this->tg->captionEdits[0]['markup'])->toBeNull();
});

it('shows "Спасибо" again on the same answer without writing or counting it twice', function (): void {
    $delivery = PollFixtures::delivered();
    pollDispatch(pollTapUpdate($delivery, '1'));

    pollDispatch(pollTapUpdate($delivery, '1'));

    expect($this->tg->textEdits)->toHaveCount(2)
        ->and($this->tg->textEdits[1]['text'])->toContain('Спасибо')
        ->and(pollVotesFor($delivery))->toBe([pollOption($delivery, 1)])
        ->and(ProductEvent::query()->where('name', ProductEventName::PollAnswered->value)->count())->toBe(1);
});

it('brings the options back on «Изменить ответ» and records the change as changed', function (): void {
    $delivery = PollFixtures::delivered();
    pollDispatch(pollTapUpdate($delivery, '0'));

    pollDispatch(pollTapUpdate($delivery, 'c'));

    $edit = $this->tg->lastTextEdit();
    expect($edit['text'])->toBe('Какой формат занятий вам удобнее?')
        ->and(pollButtons($edit['markup']))->toBe([
            "Вариант 1|pl1:{$delivery->token}:0",
            "Вариант 2|pl1:{$delivery->token}:1",
            "Вариант 3|pl1:{$delivery->token}:2",
        ]);

    pollDispatch(pollTapUpdate($delivery, '2'));

    expect(pollVotesFor($delivery))->toBe([pollOption($delivery, 2)])
        ->and(ProductEvent::query()->where('name', ProductEventName::PollAnswered->value)->orderBy('id')->get()->pluck('props.changed')->all())->toBe([false, true]);
});

it('numbers the options in the text and on the buttons when an option is long', function (): void {
    $delivery = PollFixtures::delivered();
    PollOption::query()->where('poll_id', $delivery->poll_id)->where('position', 2)->update(['label' => json_encode(['ru' => 'Очень длинный вариант ответа на вопрос', 'uz' => 'Juda uzun javob varianti savolga'])]);
    pollDispatch(pollTapUpdate($delivery, '0'));

    pollDispatch(pollTapUpdate($delivery, 'c'));

    $edit = $this->tg->lastTextEdit();
    expect($edit['text'])->toBe("Какой формат занятий вам удобнее?\n\n1. Вариант 1\n2. Вариант 2\n3. Очень длинный вариант ответа на вопрос")
        ->and(pollButtons($edit['markup']))->toBe(["1|pl1:{$delivery->token}:0", "2|pl1:{$delivery->token}:1", "3|pl1:{$delivery->token}:2"]);
});

it('keeps a locked answer and says so with a toast', function (): void {
    $delivery = PollFixtures::delivered(['allow_change' => false]);
    pollDispatch(pollTapUpdate($delivery, '0'));
    $update = pollTapUpdate($delivery, '1');

    pollDispatch($update);

    expect(pollVotesFor($delivery))->toBe([pollOption($delivery, 0)])
        ->and($this->tg->answeredTexts[$update['callback_query']['id']])->toBe(['text' => 'Ответ уже принят, изменить его нельзя.', 'show_alert' => false])
        ->and($this->tg->lastTextEdit()['text'])->toContain('Спасибо');
});

// --- several answers -----------------------------------------------------------------------------------------------------

it('ticks and unticks options on the keyboard only, refuses one over the limit and an empty submit with toasts', function (): void {
    $delivery = PollFixtures::delivered(['allows_multiple' => true, 'max_choices' => 2]);

    pollDispatch(pollTapUpdate($delivery, '0'));
    pollDispatch(pollTapUpdate($delivery, '2'));
    $over = pollTapUpdate($delivery, '1');
    pollDispatch($over);

    expect($this->tg->textEdits)->toBe([])
        ->and($this->tg->edits)->toHaveCount(2)
        ->and(pollButtons($this->tg->edits[1]['markup']))->toBe([
            "☑ Вариант 1|pl1:{$delivery->token}:0",
            "☐ Вариант 2|pl1:{$delivery->token}:1",
            "☑ Вариант 3|pl1:{$delivery->token}:2",
            "✅ Отправить|pl1:{$delivery->token}:s",
        ])
        ->and($this->tg->answeredTexts[$over['callback_query']['id']])->toBe(['text' => 'Можно выбрать не больше 2.', 'show_alert' => false]);

    pollDispatch(pollTapUpdate($delivery, '0'));
    pollDispatch(pollTapUpdate($delivery, '2'));
    $empty = pollTapUpdate($delivery, 's', from: ['language_code' => 'uz']);
    pollDispatch($empty);

    expect($this->tg->answeredTexts[$empty['callback_query']['id']])->toBe(['text' => 'Выберите хотя бы один вариант.', 'show_alert' => false])
        ->and(pollVotesFor($delivery))->toBe([]);

    pollDispatch(pollTapUpdate($delivery, '1'));
    pollDispatch(pollTapUpdate($delivery, 's'));

    expect(pollVotesFor($delivery))->toBe([pollOption($delivery, 1)])
        ->and($this->tg->lastTextEdit()['text'])->toBe("Какой формат занятий вам удобнее?\n\n✅ Спасибо! Ответ принят.");
});

it('says the limit in Uzbek for an Uzbek delivery', function (): void {
    $delivery = PollFixtures::delivered(['allows_multiple' => true, 'max_choices' => 1], ['locale' => 'uz']);
    pollDispatch(pollTapUpdate($delivery, '0'));
    $over = pollTapUpdate($delivery, '1');

    pollDispatch($over);

    expect($this->tg->answeredTexts[$over['callback_query']['id']]['text'])->toBe("Ko'pi bilan 1 ta variant tanlash mumkin.");
});

// --- closed and refused --------------------------------------------------------------------------------------------------

it('answers a closed poll with an alert and removes the buttons', function (string $locale, string $text): void {
    $delivery = PollFixtures::delivered(['status' => PollStatus::Closed, 'closed_at' => now()], ['locale' => $locale]);
    $update = pollTapUpdate($delivery, '0');

    pollDispatch($update);

    expect($this->tg->answeredTexts[$update['callback_query']['id']])->toBe(['text' => $text, 'show_alert' => true])
        ->and($this->tg->edits)->toBe([['chat_id' => $delivery->telegram_user_id, 'message_id' => 4242, 'markup' => null]])
        ->and(pollVotesFor($delivery))->toBe([]);
})->with([
    'ru' => ['ru', 'Опрос завершён.'],
    'uz' => ['uz', "So'rovnoma yakunlandi."],
]);

it('treats a passed deadline as closed before the closing command runs', function (): void {
    $delivery = PollFixtures::delivered(['closes_at' => now()->subMinute()]);
    $update = pollTapUpdate($delivery, '0');

    pollDispatch($update);

    expect($this->tg->answeredTexts[$update['callback_query']['id']]['text'])->toBe('Опрос завершён.')
        ->and(pollVotesFor($delivery))->toBe([]);
});

it('refuses every tap that is not the recipient\'s own in the private chat of the message', function (Closure $make, string $text): void {
    $delivery = PollFixtures::delivered();
    $update = $make($delivery);

    pollDispatch($update);

    expect($this->tg->answeredTexts[$update['callback_query']['id']])->toBe(['text' => $text, 'show_alert' => true])
        ->and($this->tg->textEdits)->toBe([])
        ->and($this->tg->edits)->toBe([])
        ->and($this->tg->captionEdits)->toBe([])
        ->and(pollVotesFor($delivery))->toBe([])
        ->and(PollDelivery::query()->whereKey($delivery->id)->value('answered_at'))->toBeNull();
})->with([
    'someone else in his own chat' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', ['chat' => ['id' => 99_000_001, 'type' => 'private']], ['id' => 99_000_001]), 'Опрос недоступен.'],
    'a forwarded copy' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', ['chat' => ['id' => 99_000_002, 'type' => 'private']]), 'Опрос недоступен.'],
    'a group' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', ['chat' => ['id' => -100123, 'type' => 'supergroup']]), 'Опрос недоступен.'],
    'a forged token' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', data: 'pl1:AAAAAAAAAAAA:0'), 'Опрос недоступен.'],
    'an option the poll does not have' => [fn (PollDelivery $d): array => pollTapUpdate($d, '7'), 'Опрос недоступен.'],
    'a trailing newline' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', data: "pl1:{$d->token}:0\n"), 'Опрос недоступен.'],
    'a longer token' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', data: "pl1:{$d->token}A:0"), 'Опрос недоступен.'],
    'an inaccessible message' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', ['date' => 0]), 'Опрос недоступен.'],
    'in Uzbek by the tapper\'s language' => [fn (PollDelivery $d): array => pollTapUpdate($d, '0', ['chat' => ['id' => -100123, 'type' => 'group']], ['language_code' => 'uz']), "So'rovnoma mavjud emas."],
]);

it('refuses a malformed button before any statement of the vote', function (): void {
    $delivery = PollFixtures::delivered();
    $update = pollTapUpdate($delivery, '0', data: "pl1:{$delivery->token}:0\n");

    $queries = QueryLog::during(fn () => pollDispatch($update));

    expect(QueryLog::matching($queries, '/poll_/'))->toBe([]);
});

it('writes no event for the administrator\'s test send', function (): void {
    $poll = PollFixtures::poll();
    $delivery = PollDelivery::factory()->test()->sent(4242)->create(['poll_id' => $poll->id]);

    pollDispatch(pollTapUpdate($delivery, '0'));

    expect(pollVotesFor($delivery))->toBe([pollOption($delivery, 0)])
        ->and($this->tg->lastTextEdit()['text'])->toContain('Спасибо')
        ->and(ProductEvent::query()->where('name', ProductEventName::PollAnswered->value)->count())->toBe(0);
});

// --- refused edits -------------------------------------------------------------------------------------------------------

it('takes "message is not modified" quietly and logs any other refused edit', function (): void {
    Log::spy();
    $delivery = PollFixtures::delivered();
    $this->tg->failTextEdits = true;

    pollDispatch(pollTapUpdate($delivery, '0'));

    Log::shouldNotHaveReceived('warning', ['telegram.poll_edit_failed', Mockery::any()]);
    expect(pollVotesFor($delivery))->toBe([pollOption($delivery, 0)]);

    $photo = PollFixtures::delivered();
    $this->tg->failCaptionEditsWith = "message can't be edited";

    pollDispatch(pollTapUpdate($photo, '0', ['photo' => [['file_id' => 'x']]]));

    Log::shouldHaveReceived('warning')->with('telegram.poll_edit_failed', ['code' => 400])->once();
    expect(pollVotesFor($photo))->toBe([pollOption($photo, 0)]);
});

it('records the vote even when the query is too old to answer', function (): void {
    $delivery = PollFixtures::delivered();
    $this->tg->failAnswers = true;

    pollDispatch(pollTapUpdate($delivery, '0'));

    expect(pollVotesFor($delivery))->toBe([pollOption($delivery, 0)])
        ->and($this->tg->lastTextEdit()['text'])->toContain('Спасибо');
});

// --- the view cache ------------------------------------------------------------------------------------------------------

it('reads the texts from the view cache and reads them again after the cache is forgotten', function (): void {
    $delivery = PollFixtures::delivered();
    $views = app(PollViewCache::class);

    expect($views->get($delivery->poll_id)['question']['ru'])->toBe('Какой формат занятий вам удобнее?');

    $delivery->poll->forceFill(['question' => ['ru' => 'Новый вопрос', 'uz' => 'Yangi savol']])->save();
    expect($views->get($delivery->poll_id)['question']['ru'])->toBe('Какой формат занятий вам удобнее?');

    $views->forget($delivery->poll_id);
    expect($views->get($delivery->poll_id)['question']['ru'])->toBe('Новый вопрос')
        ->and($views->get(999_999))->toBeNull();
});

it('never shows the text of before an edit, even when a tap that read the poll before the edit puts the old view into the cache after it', function (): void {
    $delivery = PollFixtures::delivered();
    $poll = $delivery->poll;
    $views = app(PollViewCache::class);
    $old = $views->get($poll->id, PollViewCache::version($poll->getRawOriginal('updated_at')));

    // The edit commits (a new `updated_at`), the editor forgets the key - and then a tap decided on the old poll writes the old view.
    $poll->forceFill(['question' => ['ru' => 'Новый вопрос', 'uz' => 'Yangi savol'], 'updated_at' => now()->addMinute()])->save();
    $views->forget($poll->id);
    PureCache::store()->put(PollViewCache::key($poll->id), $old, PollViewCache::TTL);

    pollDispatch(pollTapUpdate($delivery, '0'));

    expect($this->tg->lastTextEdit()['text'])->toContain('Новый вопрос')
        ->and($views->get($poll->id)['question']['ru'])->toBe('Новый вопрос')
        ->and($views->get($poll->id)['version'])->toBe(now()->addMinute()->startOfSecond()->getTimestamp());
});

// --- the budget of one tap -----------------------------------------------------------------------------------------------

it('costs one tap only the statements of the vote, the dispatcher\'s one and the event', function (): void {
    $single = PollFixtures::delivered();
    $closed = PollFixtures::delivered(['status' => PollStatus::Closed, 'closed_at' => now()]);
    $multiple = PollFixtures::delivered(['allows_multiple' => true]);
    $views = app(PollViewCache::class);
    $views->get($single->poll_id);
    $views->get($multiple->poll_id);

    $count = fn (array $update): int => count(QueryLog::during(fn () => pollDispatch($update)));

    // 1 = DmReachability::markReachable of the dispatcher; then tap(): 1 (refused / closed), 3 (first answer), 2 (the same again,
    // «Изменить ответ», a toggle), 5 (a change); + 1 for the event of an answer.
    expect($count(pollTapUpdate($single, '0', data: 'pl1:AAAAAAAAAAAA:0')))->toBe(1 + 1)
        ->and($count(pollTapUpdate($closed, '0')))->toBe(1 + 1)
        ->and($count(pollTapUpdate($single, '0')))->toBe(1 + 3 + 1)
        ->and($count(pollTapUpdate($single, '0')))->toBe(1 + 2)
        ->and($count(pollTapUpdate($single, 'c')))->toBe(1 + 2)
        ->and($count(pollTapUpdate($single, '1')))->toBe(1 + 5 + 1)
        ->and($count(pollTapUpdate($multiple, '0')))->toBe(1 + 2);

    // A missing view entry costs its two reads once, then it is cached again.
    $views->forget($single->poll_id);
    expect($count(pollTapUpdate($single, '1')))->toBe(1 + 2 + 2)
        ->and($count(pollTapUpdate($single, '1')))->toBe(1 + 2);
});
