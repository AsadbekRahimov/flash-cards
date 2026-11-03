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
