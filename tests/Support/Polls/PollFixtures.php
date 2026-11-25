<?php

declare(strict_types=1);

namespace Tests\Support\Polls;

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Services\PollLauncher;
use App\Models\Learner;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Builders of the poll tests (docs/35 O3/O4). */
final class PollFixtures
{
    /** @param array<string, mixed> $attributes */
    public static function poll(array $attributes = [], int $options = 3): Poll
    {
        $poll = Poll::factory()->create($attributes);

        for ($position = 0; $position < $options; $position++) {
            PollOption::factory()->create([
                'poll_id' => $poll->id,
                'position' => $position,
                'label' => ['ru' => 'Вариант '.($position + 1), 'uz' => 'Variant '.($position + 1)],
            ]);
        }

        return $poll->refresh();
    }

    /** A test delivery that reached an administrator: the launch needs one. */
    public static function tested(Poll $poll): PollDelivery
    {
        return PollDelivery::factory()->test()->sent()->create(['poll_id' => $poll->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<Learner>
     */
    public static function learners(int $count, array $attributes = []): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = Learner::factory()->onboarded()->create($attributes);
        }

        return $out;
    }

    public static function admin(?int $telegramUserId = 555000111): User
    {
        return User::factory()->create(['telegram_user_id' => $telegramUserId]);
    }

    /**
     * A launched poll with `$recipients` onboarded learners, the chains NOT run (the queue is faked during the launch).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function launched(int $recipients, array $attributes = [], int $options = 3): Poll
    {
        self::learners($recipients);
        $poll = self::poll($attributes, $options);
        self::tested($poll);

        $real = Queue::getFacadeRoot();
        Queue::fake();
        app(PollLauncher::class)->launch($poll, self::admin(null));
        Queue::swap($real);

        return $poll->refresh();
    }

    /**
     * A delivery that went out to a learner of a launched poll, ready for taps.
     *
     * @param  array<string, mixed>  $poll
     * @param  array<string, mixed>  $delivery
     */
    public static function delivered(array $poll = [], array $delivery = [], int $options = 3): PollDelivery
    {
        $model = self::poll(['status' => PollStatus::Sent, 'launched_at' => now()->subHour(), ...$poll], $options);
        $learner = Learner::factory()->onboarded()->create();

        return PollDelivery::query()->create([
            'poll_id' => $model->id,
            'learner_id' => $learner->id,
            'telegram_user_id' => $learner->telegram_user_id,
            'token' => Str::random(12),
            'locale' => 'ru',
            'status' => PollDeliveryStatus::Sent,
            'message_id' => 4242,
            'sent_at' => now()->subHour(),
            ...$delivery,
        ]);
    }
}
