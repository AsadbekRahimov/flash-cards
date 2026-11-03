<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('twa.jwt.secret', 'load-test-secret-load-test-secret-0123456789');
    $this->path = storage_path('framework/testing/load-tokens-'.uniqid().'.json');
});

afterEach(function (): void {
    File::delete($this->path);
});

it('creates test learners and writes tokens that TwaAuth accepts', function (): void {
    $course = Course::factory()->published()->create();

    $this->artisan('load:tokens', ['--count' => 3, '--ttl' => 600, '--output' => $this->path])->assertSuccessful();

    $data = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
    expect($data['course'])->toBe($course->slug)
        ->and($data['learners'])->toHaveCount(3)
        ->and(Learner::query()->count())->toBe(3);

    $jwt = new JwtService('load-test-secret-load-test-secret-0123456789', 600);
    foreach ($data['learners'] as $row) {
        expect($jwt->verify($row['token'])['learner_id'])->toBe($row['learner_id']);
    }

    $first = $data['learners'][0];
    $this->getJson('/api/twa/me', ['Authorization' => 'Bearer '.$first['token']])->assertOk();
    expect(Learner::query()->find($first['learner_id'])?->active_course_id)->toBe($course->id);
});

it('reuses the same learners on a repeat run', function (): void {
    $this->artisan('load:tokens', ['--count' => 2, '--output' => $this->path])->assertSuccessful();
    $this->artisan('load:tokens', ['--count' => 2, '--output' => $this->path])->assertSuccessful();

    expect(Learner::query()->count())->toBe(2);
});

it('prints only the JSON to stdout with --output=-', function (): void {
    Artisan::call('load:tokens', ['--count' => 2, '--output' => '-']);

    $data = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($data['learners'])->toHaveCount(2);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('load:tokens', ['--count' => 1, '--output' => $this->path])->assertFailed();

    expect(Learner::query()->count())->toBe(0)
        ->and(file_exists($this->path))->toBeFalse();
});

it('rejects a wrong number of learners, an unknown course and a missing secret', function (): void {
    $this->artisan('load:tokens', ['--count' => 0, '--output' => $this->path])->assertFailed();
    $this->artisan('load:tokens', ['--count' => 99999, '--output' => $this->path])->assertFailed();
    $this->artisan('load:tokens', ['--count' => 1, '--course' => 'nope', '--output' => $this->path])->assertFailed();

    config()->set('twa.jwt.secret', '');
    $this->artisan('load:tokens', ['--count' => 1, '--output' => $this->path])->assertFailed();

    expect(Learner::query()->count())->toBe(0);
});

it('refuses outside local, testing and staging (an allowlist, so "prod" is refused too)', function (): void {
    app()->detectEnvironment(fn (): string => 'prod');

    $this->artisan('load:tokens', ['--count' => 1, '--output' => $this->path])->assertFailed();

    expect(Learner::query()->count())->toBe(0)
        ->and(file_exists($this->path))->toBeFalse();
});

it('works in staging', function (): void {
    app()->detectEnvironment(fn (): string => 'staging');

    $this->artisan('load:tokens', ['--count' => 1, '--output' => $this->path])->assertSuccessful();
});

it('marks the learners it creates and silences their DMs', function (): void {
    $this->artisan('load:tokens', ['--count' => 2, '--output' => $this->path])->assertSuccessful();

    $learners = Learner::query()->get();
    expect($learners)->toHaveCount(2);
    foreach ($learners as $learner) {
        expect($learner->meta['load_test'])->toBeTrue()
            ->and($learner->dm_blocked_at)->not->toBeNull();
    }
});

it('refuses to touch a real learner with a colliding Telegram ID and leaves the row unchanged', function (): void {
    $real = Learner::factory()->create(['telegram_user_id' => 8_000_000_001, 'blocked_at' => now(), 'meta' => []]);
    $before = $real->fresh()->getAttributes();

    $this->artisan('load:tokens', ['--count' => 3, '--output' => $this->path])->assertFailed();

    expect($real->fresh()->getAttributes())->toBe($before)
        ->and(Learner::query()->count())->toBe(1)
        ->and(file_exists($this->path))->toBeFalse();
});

it('writes the token file with mode 0600', function (): void {
    $this->artisan('load:tokens', ['--count' => 1, '--output' => $this->path])->assertSuccessful();

    expect(substr(sprintf('%o', fileperms($this->path)), -4))->toBe('0600')
        ->and(glob($this->path.'.*'))->toBe([]);
});

it('accepts --output only inside storage/ or "-"', function (string $output): void {
    $this->artisan('load:tokens', ['--count' => 1, '--output' => $output])->assertFailed();

    expect(Learner::query()->count())->toBe(0)
        ->and(file_exists(base_path($output)))->toBeFalse();
})->with(['public/x.json', 'storage/../public/x.json', 'storage/app/../../public/x.json', '/tmp/x.json']);
