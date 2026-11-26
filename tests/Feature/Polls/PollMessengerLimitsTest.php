<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\PollMessenger;
use App\Domain\Telegram\Support\TelegramText;
use App\Models\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Review of release 5, item 8: Telegram counts the caption (1024) and the text (4096) of a message in UTF-16 code units - an emoji is
 * 2 - so the poll messenger measures with {@see TelegramText}, not mb_strlen (which counts an emoji as 1 and lets a text through that
 * Telegram then refuses).
 */

uses(RefreshDatabase::class);

it('cuts the question after an answer to the caption limit counted in UTF-16, between graphemes', function (): void {
    $poll = Poll::factory()->withOptions(2)->create(['question' => ['ru' => str_repeat('🗳', 700), 'uz' => 'Savol']]);
    $messenger = app(PollMessenger::class);

    $text = $messenger->answered($poll, 'ru');

    expect(mb_strlen($text))->toBeLessThan(1024)               // what the old check let through as it was ...
        ->and(TelegramText::length($text))->toBeLessThanOrEqual(1024) // ... is now cut to what Telegram takes
        ->and($text)->toContain('…')
        ->and($messenger->answered($poll, 'uz'))->toStartWith('Savol');
});

it('refuses to launch a poll whose question of emoji is longer than a caption in UTF-16', function (): void {
    $poll = Poll::factory()->withOptions(2)->create(['question' => ['ru' => str_repeat('🗳', 600), 'uz' => 'Savol'], 'image_path' => 'polls/x.jpg']);
    $fits = Poll::factory()->withOptions(2)->create(['question' => ['ru' => str_repeat('🗳', 500), 'uz' => 'Savol'], 'image_path' => 'polls/x.jpg']);

    expect(app(PollMessenger::class)->fits($poll, $poll->options()->orderBy('position')->get()))->toBeFalse()
        ->and(app(PollMessenger::class)->fits($fits, $fits->options()->orderBy('position')->get()))->toBeTrue();
});
