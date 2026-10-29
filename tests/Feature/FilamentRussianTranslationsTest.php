<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

uses(RefreshDatabase::class);

/*
 * Both panels (`/admin`, `/org`) are Russian. Filament ships `ru` for most of its strings, but not for all of them:
 * the keys it lacks are completed in `lang/vendor/<namespace>/ru/<file>.php` (Laravel merges these over the package
 * file with array_replace_recursive, so the override carries only the missing keys). Without them the interface shows
 * the raw key (`filament-tables::table.loading`) or the English text.
 */

beforeEach(function (): void {
    app()->setLocale('ru');
});

/**
 * Translation namespaces of the installed Filament packages: namespace => resolved path of the package `lang` folder.
 *
 * @return array<string, string>
 */
function frt_namespaces(): array
{
    $root = realpath(base_path('vendor/filament'));
    expect($root)->not->toBeFalse();

    $found = [];

    foreach (app('translator')->getLoader()->namespaces() as $namespace => $path) {
        $real = realpath($path);

        if ($real !== false && str_starts_with($real.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR) && is_dir($real.'/en')) {
            $found[$namespace] = $real;
        }
    }

    ksort($found);

    return $found;
}

/**
 * All texts of one locale below `$langDir`/<locale>/, flat: 'group.nested.key' => text (the group may be a path:
 * 'components/loading-section.label').
 *
 * @return array<string, string>
 */
function frt_texts(string $langDir, string $locale): array
{
    $base = $langDir.'/'.$locale;

    if (! is_dir($base)) {
        return [];
    }

    $texts = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $group = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base) + 1, -4));

        foreach (Arr::dot(require $file->getPathname()) as $key => $text) {
            if (is_string($text)) {
                $texts["{$group}.{$key}"] = $text;
            }
        }
    }

    return $texts;
}

/** @return list<string> */
function frt_placeholders(string $text): array
{
    preg_match_all('/:([A-Za-z_]+)/', $text, $matches);

    return array_values(array_unique($matches[1]));
}

it('finds the translation files of every Filament package it is asked to cover', function (): void {
    $namespaces = array_keys(frt_namespaces());

    expect($namespaces)->toContain(
        'filament', 'filament-actions', 'filament-forms', 'filament-infolists', 'filament-notifications',
        'filament-panels', 'filament-query-builder', 'filament-schemas', 'filament-tables', 'filament-widgets',
    );
});

it('resolves every Filament translation key of every package in ru, so no raw key or English text reaches the panels', function (): void {
    $translator = app('translator');
    $checked = 0;
    $unresolved = [];

    foreach (frt_namespaces() as $namespace => $dir) {
        foreach (array_keys(frt_texts($dir, 'en')) as $key) {
            $checked++;

            if (! $translator->hasForLocale("{$namespace}::{$key}", 'ru')) {
                $unresolved[] = "{$namespace}::{$key}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(500)
        ->and($unresolved)->toBe([]);
});

it('keeps lang/vendor/filament*/ru to keys the packages lack in ru, written for existing keys and with the placeholders of the English text', function (): void {
    $namespaces = frt_namespaces();
    $problems = [];

    foreach (glob(lang_path('vendor/filament*'), GLOB_ONLYDIR) ?: [] as $dir) {
        if (! array_key_exists(basename($dir), $namespaces)) {
            $problems[] = basename($dir).': not a translation namespace of an installed Filament package';
        }
    }

    foreach ($namespaces as $namespace => $dir) {
        $english = frt_texts($dir, 'en');
        $packageRu = frt_texts($dir, 'ru');

        foreach (frt_texts(lang_path("vendor/{$namespace}"), 'ru') as $key => $text) {
            if (! isset($english[$key])) {
                $problems[] = "{$namespace}::{$key}: no such key in the package (a Filament update removed or renamed it)";

                continue;
            }

            if (isset($packageRu[$key])) {
                $problems[] = "{$namespace}::{$key}: the package already translates it, drop it from the override";
            }

            foreach (frt_placeholders($english[$key]) as $placeholder) {
                if (! str_contains($text, ':'.$placeholder)) {
                    $problems[] = "{$namespace}::{$key}: the Russian text lost :{$placeholder}";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('declines the result counters by the Russian plural rule', function (string $key, int $count, string $expected): void {
    expect(trans_choice($key, $count, ['count' => $count], 'ru'))->toBe($expected);
})->with([
    'table: none' => ['filament-tables::table.result_count', 0, 'Результатов нет'],
    'table: one' => ['filament-tables::table.result_count', 1, 'Результатов: 1'],
    'table: many' => ['filament-tables::table.result_count', 25, 'Результатов: 25'],
    'search: 1' => ['filament-panels::global-search.results_count', 1, 'Показан 1 результат.'],
    'search: 2' => ['filament-panels::global-search.results_count', 2, 'Показано 2 результата.'],
    'search: 5' => ['filament-panels::global-search.results_count', 5, 'Показано 5 результатов.'],
    'search: 11' => ['filament-panels::global-search.results_count', 11, 'Показано 11 результатов.'],
    'search: 21' => ['filament-panels::global-search.results_count', 21, 'Показан 21 результат.'],
    'search: 22' => ['filament-panels::global-search.results_count', 22, 'Показано 22 результата.'],
    'search: 0' => ['filament-panels::global-search.results_count', 0, 'Показано 0 результатов.'],
]);

/** What Filament prints when a key is missing: `filament-tables::table.loading`. */
function frt_raw_keys(string $html): array
{
    preg_match_all('#\bfilament(?:-[a-z]+)?::[\w/.\-]+#', $html, $matches);

    return array_values(array_unique($matches[0]));
}

it('prints the Russian table texts of Filament on a /admin list page', function (): void {
    test()->seed(RolePermissionSeeder::class);
    Lesson::factory()->count(2)->create();

    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('admin');

    $html = (string) test()->actingAs($user)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])
        ->get('/admin/lessons')
        ->assertOk()
        ->assertSee('Результатов: 2')
        ->assertSee('Загрузка...')
        ->getContent();

    expect(frt_raw_keys($html))->toBe([]);
});

it('prints the Russian table texts of Filament on a /org list page', function (): void {
    test()->seed(RolePermissionSeeder::class);
    $organization = Organization::factory()->create(['slug' => 'center-a', 'locale' => 'ru']);
    TelegramGroup::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);

    $owner = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationRole::Owner->value,
    ]);

    $html = (string) test()->actingAs($owner, 'org')
        ->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $owner->id])
        ->get('/org/center-a/groups')
        ->assertOk()
        ->assertSee('Результатов: 1')
        ->assertSee('Загрузка...')
        ->getContent();

    expect(frt_raw_keys($html))->toBe([]);
});
