<?php

declare(strict_types=1);

use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\DTO\ReminderQuiz;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Services\ReminderQuizStore;
use App\Domain\Learning\Enums\ExerciseType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
 * The state of a reminder question (docs/19 §2.2, §3): a versioned key per learner, a TTL, scalars only, numbers read back as numbers.
 */

function reminderQuizStoreQuiz(): ReminderQuiz
{
    return new ReminderQuiz(55, 'house', ExerciseType::Meaning, ['дом', 'кот', 'лес', 'мир'], 2, 'дом', 'AbC123xyZ9');
}

it('keeps the question under a versioned key of the learner, for the configured number of hours', function (): void {
    config(['learning.reminders.quiz.ttl_hours' => 26]);
    $now = CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC');
    $this->travelTo($now);
    $store = app(ReminderQuizStore::class);

    $store->put(7, reminderQuizStoreQuiz(), new ReminderContent(ReminderKind::Review, 4, 3), $now);

    expect(ReminderQuizStore::key(7))->toBe('reminders:quiz:v1:7')
        ->and(Cache::has('reminders:quiz:v1:7'))->toBeTrue();

    $state = $store->get(7);
    expect($state)->toMatchArray([
        'token' => 'AbC123xyZ9', 'word_id' => 55, 'exercise' => 'meaning', 'word' => 'house', 'answer' => 'дом',
        'options' => ['дом', 'кот', 'лес', 'мир'], 'correct' => 2, 'kind' => 'review', 'due_count' => 4,
        'sent_at' => $now->getTimestamp(), 'expires_at' => $now->getTimestamp() + 26 * 3600, 'answered_at' => null, 'picked' => null,
    ]);

    $this->travelTo($now->addHours(25));
    expect($store->get(7))->not->toBeNull();

    $this->travelTo($now->addHours(27));
    expect($store->get(7))->toBeNull();
});

it('stores nothing but scalars and lists of them', function (): void {
    $now = CarbonImmutable::now();
    app(ReminderQuizStore::class)->put(7, reminderQuizStoreQuiz(), new ReminderContent(ReminderKind::Review, 4, 3), $now);

    $raw = Cache::get(ReminderQuizStore::key(7));
    $plain = static function (mixed $value) use (&$plain): bool {
        return is_array($value) ? array_reduce($value, static fn (bool $ok, mixed $v): bool => $ok && $plain($v), true) : ($value === null || is_scalar($value));
    };

    expect($raw)->toBeArray()
        ->and($plain($raw))->toBeTrue()
        // JSON round trip (the Redis serializer of the project) gives the same array.
        ->and(json_decode((string) json_encode($raw), true))->toBe($raw);
});

it('reads the numbers of an entry written as strings back as integers', function (): void {
    Cache::put(ReminderQuizStore::key(9), [
        'token' => 'AbC123xyZ9', 'word_id' => '55', 'exercise' => 'meaning', 'word' => 'house', 'answer' => 'дом',
        'options' => ['дом', 'кот', 'лес', 'мир'], 'correct' => '2', 'kind' => 'review', 'due_count' => '4',
        'sent_at' => '1700000000', 'expires_at' => '1700093600', 'answered_at' => '1700000100', 'picked' => '1',
    ], 600);

    $state = app(ReminderQuizStore::class)->get(9);

    expect($state['correct'])->toBe(2)
        ->and($state['word_id'])->toBe(55)
        ->and($state['due_count'])->toBe(4)
        ->and($state['sent_at'])->toBe(1700000000)
        ->and($state['expires_at'])->toBe(1700093600)
        ->and($state['answered_at'])->toBe(1700000100)
        ->and($state['picked'])->toBe(1);
});

it('answers null for a missing or malformed entry and forgets one', function (): void {
    $store = app(ReminderQuizStore::class);

    expect($store->get(11))->toBeNull();

    Cache::put(ReminderQuizStore::key(11), 'garbage', 60);
    expect($store->get(11))->toBeNull();

    Cache::put(ReminderQuizStore::key(11), ['token' => 'x', 'options' => 'no'], 60);
    expect($store->get(11))->toBeNull();

    $store->put(11, reminderQuizStoreQuiz(), new ReminderContent(ReminderKind::Review, 1, 0), CarbonImmutable::now());
    $store->forget(11);
    expect($store->get(11))->toBeNull();
});

it('marks the answer without stretching the life of the question', function (): void {
    $now = CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC');
    $this->travelTo($now);
    $store = app(ReminderQuizStore::class);
    $store->put(7, reminderQuizStoreQuiz(), new ReminderContent(ReminderKind::Review, 4, 3), $now);

    $answeredAt = $now->addHours(20);
    $this->travelTo($answeredAt);
    $state = $store->get(7);
    $store->markAnswered(7, $state ?? [], 1, $answeredAt);

    expect($store->get(7))->toMatchArray(['picked' => 1, 'answered_at' => $answeredAt->getTimestamp(), 'expires_at' => $now->getTimestamp() + 26 * 3600]);

    $this->travelTo($now->addHours(27));
    expect($store->get(7))->toBeNull();
});
