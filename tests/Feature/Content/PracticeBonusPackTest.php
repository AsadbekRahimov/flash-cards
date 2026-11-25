<?php

declare(strict_types=1);

use App\Domain\Content\Services\CoursePackImporter;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Services\PracticeImporter;
use App\Models\PracticeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('publishes the bonus exercises for each level and reimports them without changes', function (string $slug, array $levels): void {
    $courseResult = app(CoursePackImporter::class)->import(base_path("database/data/courses/{$slug}"), create: true);
    expect($courseResult->errors)->toBe([])->and($courseResult->ok())->toBeTrue();

    $raw = (string) file_get_contents(base_path("database/data/practice/{$slug}-bonus.json"));
    $pack = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $expectedTypes = ['collocation' => 5, 'sentence' => 5, 'word_form' => 5, 'error_hunt' => 5];
    $counts = [];
    $targets = [];

    foreach ($pack['items'] as $item) {
        $level = $slug === 'english-a1-a2' && $item['lesson']['stage'] >= 11
            ? 'A2'
            : $levels[0];
        expect($item['level'])->toBe($level)
            ->and($item['key'])->toStartWith('bonus-')
            ->and($item['target_words'])->toHaveCount(1);
        $target = $item['target_words'][0];
        expect($targets)->not->toHaveKey($target);
        $targets[$target] = true;
        $counts[$level][$item['type']] = ($counts[$level][$item['type']] ?? 0) + 1;
    }

    expect(array_keys($counts))->toEqualCanonicalizing($levels);
    foreach ($counts as $stock) {
        expect($stock)->toEqual($expectedTypes);
    }

    $total = 20 * count($levels);
    $importer = app(PracticeImporter::class);
    $first = $importer->importAsSystem($raw, publish: true);
    expect($first->toArray())->toBe([
        'added' => $total, 'updated' => 0, 'skipped' => 0, 'published' => $total, 'errors' => [], 'aborted' => false,
    ]);
    expect(PracticeItem::query()->where('status', PracticeItemStatus::Published)->count())->toBe($total);

    $second = $importer->importAsSystem($raw, publish: true);
    expect($second->toArray())->toBe([
        'added' => 0, 'updated' => 0, 'skipped' => $total, 'published' => 0, 'errors' => [], 'aborted' => false,
    ]);
})->with([
    ['english-a1-a2', ['A1', 'A2']],
    ['english-b1', ['B1']],
    ['english-b2', ['B2']],
    ['english-c1', ['C1']],
    ['english-c2', ['C2']],
]);
