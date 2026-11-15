<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordTranslationWriter;
use App\Domain\Learning\Services\LearningCache;
use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

/*
 * docs/19 §3: a write drops what it made stale. The pool of distractors of a lesson (`lesson:{id}:distractors:v1`)
 * holds `word` and `translation`, so every admin path that edits them (or `is_active`) must reach `forgetLesson`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $this->lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    $this->words = [];
    foreach (['alpha', 'beta', 'gamma'] as $i => $text) {
        $this->words[] = Word::factory()->for($this->lesson)->create(['word' => $text, 'translation' => "t{$i}"]);
    }
    // Warm the pool.
    app(LearningCache::class)->lessonDistractorPool($this->lesson->id);
});

/** @return list<string> */
function pooled(Lesson $lesson, string $column): array
{
    return collect(app(LearningCache::class)->lessonDistractorPool($lesson->id))->pluck($column)->all();
}

it('shows an edited word text from the word screen in the pool', function (): void {
    livewire(EditWord::class, ['record' => $this->words[1]->getRouteKey()])
        ->fillForm(['word' => 'bravo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(pooled($this->lesson, 'word'))->toContain('bravo')->not->toContain('beta');
});

it('shows an edited translation from the word screen in the pool', function (): void {
    livewire(EditWord::class, ['record' => $this->words[1]->getRouteKey()])
        ->fillForm(['translation' => 'новый'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(pooled($this->lesson, 'translation'))->toContain('новый');
});

it('drops a word that was deactivated on the word screen from the pool', function (): void {
    livewire(EditWord::class, ['record' => $this->words[1]->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(pooled($this->lesson, 'word'))->not->toContain('beta');
});

it('shows an edited word text from the lesson relation manager in the pool', function (): void {
    livewire(WordsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
        ->callTableAction('edit', $this->words[1], data: ['word' => 'bravo', 'translation' => 't1'])
        ->assertHasNoTableActionErrors();

    expect(pooled($this->lesson, 'word'))->toContain('bravo')->not->toContain('beta');
});

it('shows an edited translation from the lesson relation manager in the pool', function (): void {
    livewire(WordsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
        ->callTableAction('edit', $this->words[1], data: ['word' => 'beta', 'translation' => 'новый'])
        ->assertHasNoTableActionErrors();

    expect(pooled($this->lesson, 'translation'))->toContain('новый');
});

it('shows a word created in the lesson relation manager in the pool', function (): void {
    livewire(WordsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
        ->callTableAction('create', data: ['word' => 'delta', 'translation' => 'дельта'])
        ->assertHasNoTableActionErrors();

    expect(pooled($this->lesson, 'word'))->toContain('delta');
});

it('shows the ru translation saved on the translations screen in the pool', function (): void {
    app(WordTranslationWriter::class)->save($this->words[1], 'ru', 'через переводы');

    expect(pooled($this->lesson, 'translation'))->toContain('через переводы');
});

it('keeps the pool when a save changes nothing that it holds', function (): void {
    livewire(EditWord::class, ['record' => $this->words[1]->getRouteKey()])
        ->fillForm(['example' => 'An example.'])
        ->call('save')
        ->assertHasNoFormErrors();

    Word::query()->whereKey($this->words[1]->id)->update(['word' => 'sneaky']);

    expect(pooled($this->lesson, 'word'))->not->toContain('sneaky');
});
