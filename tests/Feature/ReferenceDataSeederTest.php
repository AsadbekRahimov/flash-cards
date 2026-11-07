<?php

declare(strict_types=1);

use App\Models\GameItem;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

it('seeds the roles and the shop items idempotently and creates no demo data', function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $items = GameItem::query()->count();
    expect($items)->toBeGreaterThan(0);

    $this->seed(ReferenceDataSeeder::class);

    expect(Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all())->toBe(['admin', 'teacher'])
        ->and(Role::query()->count())->toBe(2)
        ->and(GameItem::query()->count())->toBe($items)
        ->and(User::query()->count())->toBe(0);
});

it('runs the reference data seeder after the migrations in lf-migrate (Coolify)', function (): void {
    /** @var array{services: array{'lf-migrate': array{command: list<string>}}} $compose */
    $compose = Yaml::parseFile(base_path('docker-compose.coolify.yml'));
    $command = implode(' ', $compose['services']['lf-migrate']['command']);

    $migrate = strpos($command, 'migrate --force --database=pgsql_direct');
    $seed = strpos($command, 'db:seed --class=ReferenceDataSeeder --force');
    $recount = strpos($command, 'courses:recount-words');

    expect($migrate)->not->toBeFalse()
        ->and($seed)->toBeGreaterThan($migrate)
        ->and($recount)->toBeGreaterThan($seed)
        ->and($command)->not->toContain('\\');
});

it('runs the reference data seeder in deploy.sh', function (): void {
    expect((string) file_get_contents(base_path('deploy.sh')))->toContain('db:seed --class=ReferenceDataSeeder --force');
});
