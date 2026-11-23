<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AttentionList;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\TtsQueueStats;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\DashboardKpiWidget;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * «Требуют внимания» of /admin and the voicing (docs/26 §9.3): a warning when the queue `tts` is longer than
 * `twa.tts.queue_attention_size` (from a cached size), an urgent line while Google refused the sign-in (the halt marker).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'));
    config([
        'learning.reminders.quiz.experiment.enabled' => false,
        'twa.tts.driver' => 'google',
        'twa.tts.monthly_char_limit' => 900_000,
        'twa.tts.queue_attention_size' => 3,
    ]);
    Cache::forget(GoogleSpeechSynthesizer::HALT_KEY);
    Queue::fake();

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

function ttsQueueFill(int $jobs): void
{
    foreach (range(1, $jobs) as $id) {
        Queue::push(new PregenerateWordAudioJob($id), queue: 'tts');
    }
}

it('shows nothing while the queue tts is within the threshold', function (): void {
    ttsQueueFill(3);

    expect(app(TtsQueueStats::class)->backlog())->toBeNull()
        ->and(app(AttentionList::class)->items())->toBe([]);
});

it('warns when the queue tts is longer than the threshold', function (): void {
    ttsQueueFill(4);

    $items = app(AttentionList::class)->items();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_TTS_QUEUE, 'level' => 'warning', 'count' => 4, 'total' => 3])
        ->and(AttentionList::summary($items))->toBe(['count' => 1, 'urgent' => 0, 'kinds' => ['audio']]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Очередь озвучки')
        ->assertSee('В очереди tts 4 заданий при пороге 3');
});

it('counts the jobs of the queue tts only, whatever the driver', function (): void {
    config(['twa.tts.driver' => 'http']);
    foreach (range(1, 5) as $i) {
        Queue::push('SomeJob', queue: 'low');
    }
    expect(app(TtsQueueStats::class)->backlog())->toBeNull();

    PureCache::store()->clear();
    ttsQueueFill(4);
    expect(app(TtsQueueStats::class)->backlog())->toBe(['size' => 4, 'threshold' => 3]);
});

it('defaults to 3000 and takes a value that is not a number for the default', function (): void {
    config(['twa.tts.queue_attention_size' => null]);
    expect(TtsQueueStats::threshold())->toBe(3000);

    config(['twa.tts.queue_attention_size' => 'many']);
    expect(TtsQueueStats::threshold())->toBe(3000);
});

it('keeps the size in the pure cache: the second open asks neither the queue nor the database', function (): void {
    ttsQueueFill(4);
    app(TtsQueueStats::class)->backlog();

    ttsQueueFill(10);

    expect(app(TtsQueueStats::class)->backlog())->toBe(['size' => 4, 'threshold' => 3])
        ->and(PureCache::store()->has('admin:widget:tts_queue:v1:all'))->toBeTrue()
        ->and(QueryLog::during(fn () => app(TtsQueueStats::class)->backlog()))->toBe([]);
});

it('puts a refused sign-in of Google on top as urgent, with its code', function (string $code): void {
    Cache::put(GoogleSpeechSynthesizer::HALT_KEY, ['code' => $code], GoogleSpeechSynthesizer::HALT_SECONDS);

    $items = app(AttentionList::class)->items();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_TTS_HALTED, 'level' => 'danger', 'state' => $code]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Google TTS · вход отклонён')
        ->assertSee("Google отклонил ключ ({$code})")
        ->assertSee('audio:sample --clear-halt');

    Livewire::test(DashboardKpiWidget::class)->assertSee('1 срочное');
})->with([SpeechSynthesisException::AUTH_FAILED, SpeechSynthesisException::FORBIDDEN]);

it('shows no halt line once the marker is gone, for another driver, or for a marker it did not write', function (): void {
    Cache::put(GoogleSpeechSynthesizer::HALT_KEY, ['code' => 'tts_auth_failed'], GoogleSpeechSynthesizer::HALT_SECONDS);
    config(['twa.tts.driver' => 'http']);
    expect(app(AttentionList::class)->items())->toBe([]);

    config(['twa.tts.driver' => 'google']);
    Cache::put(GoogleSpeechSynthesizer::HALT_KEY, 'tts_forbidden', GoogleSpeechSynthesizer::HALT_SECONDS);
    expect(app(AttentionList::class)->items())->toBe([]);

    GoogleSpeechSynthesizer::clearHalt();
    expect(app(AttentionList::class)->items())->toBe([]);
});
