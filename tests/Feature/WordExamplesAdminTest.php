<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordContentEditor;
use App\Domain\Learning\Services\LearningCache;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\RelationManagers\WordExamplesRelationManager;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordExample;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $course = Course::factory()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $this->lesson = Lesson::factory()->create(['stage_id' => $stage->id]);
    $this->word = Word::factory()->create(['lesson_id' => $this->lesson->id, 'word' => 'mother', 'translation' => 'мать', 'example' => 'My mother cooks.']);
    $this->father = Word::factory()->create(['lesson_id' => $this->lesson->id, 'word' => 'Father', 'translation' => 'отец']);
});

function examplesManager(Word $word): Testable
{
    return livewire(WordExamplesRelationManager::class, ['ownerRecord' => $word, 'pageClass' => EditWord::class]);
}

/** Warms the cached examples of the word and returns the cache, to see whether a write dropped it. */
function warmExamples(Word $word): array
{
    return app(LearningCache::class)->wordExamples((int) $word->id);
}

it('adds an example with translations and drops the cache', function (): void {
    expect(warmExamples($this->word))->toBe([]);

    examplesManager($this->word)
        ->callTableAction('create', data: ['text' => 'Mother is home.', 'translations' => ['ru' => 'Мама дома.', 'uz' => '']])
        ->assertHasNoTableActionErrors();

    $row = WordExample::query()->where('word_id', $this->word->id)->sole();
    expect($row->position)->toBe(1)
        ->and($row->translations)->toBe(['ru' => 'Мама дома.'])
        // The cache was dropped: the next read sees the new row.
        ->and(warmExamples($this->word))->toBe([['text' => 'Mother is home.', 'translations' => ['ru' => 'Мама дома.']]]);
});

it('edits and deletes an example, closing the gap in the positions', function (): void {
    $editor = app(WordContentEditor::class);
    $a = $editor->addExample($this->word, 'One.', []);
    $b = $editor->addExample($this->word, 'Two.', []);
    $c = $editor->addExample($this->word, 'Three.', []);
    warmExamples($this->word);

    examplesManager($this->word)
        ->callTableAction('edit', $b, data: ['text' => 'Two!', 'translations' => ['uz' => 'Ikki!']])
        ->assertHasNoTableActionErrors();
    expect(array_column(warmExamples($this->word), 'text'))->toBe(['One.', 'Two!', 'Three.']);

    examplesManager($this->word)->callTableAction('delete', $a);

    expect(WordExample::query()->where('word_id', $this->word->id)->orderBy('position')->pluck('position', 'id')->all())
        ->toBe([$b->id => 1, $c->id => 2])
        ->and(array_column(warmExamples($this->word), 'text'))->toBe(['Two!', 'Three.']);
});

it('reorders by drag and drop through the editor and drops the cache', function (): void {
    $editor = app(WordContentEditor::class);
    $a = $editor->addExample($this->word, 'One.', []);
    $b = $editor->addExample($this->word, 'Two.', []);
    $c = $editor->addExample($this->word, 'Three.', []);
    warmExamples($this->word);

    examplesManager($this->word)->call('reorderTable', [$c->id, $a->id, $b->id]);

    expect(WordExample::query()->where('word_id', $this->word->id)->orderBy('position')->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and(array_column(warmExamples($this->word), 'text'))->toBe(['Three.', 'One.', 'Two.']);
});

it('ignores foreign ids on reorder and does not touch another word', function (): void {
    $editor = app(WordContentEditor::class);
    $a = $editor->addExample($this->word, 'One.', []);
    $b = $editor->addExample($this->word, 'Two.', []);
    $foreign = $editor->addExample($this->father, 'Foreign.', []);

    $editor->reorderExamples($this->word, [$b->id, $foreign->id]);

    expect(WordExample::query()->where('word_id', $this->word->id)->orderBy('position')->pluck('id')->all())->toBe([$b->id, $a->id])
        ->and($foreign->fresh()->position)->toBe(1);
});

it('refuses an eleventh example and hides the create action', function (): void {
    $editor = app(WordContentEditor::class);
    for ($i = 1; $i <= WordContentEditor::MAX_EXAMPLES; $i++) {
        $editor->addExample($this->word, "Example {$i}.", []);
    }

    examplesManager($this->word)->assertTableActionHidden('create');
    expect(fn () => $editor->addExample($this->word, 'Too many.', []))->toThrow(LogicException::class);
});

it('requires the text of an example', function (): void {
    examplesManager($this->word)
        ->callTableAction('create', data: ['text' => ''])
        ->assertHasTableActionErrors(['text' => 'required']);

    expect(WordExample::query()->count())->toBe(0);
});

it('saves the confused-with tags from the word form into meta', function (): void {
    $this->word->update(['meta' => ['other' => 'kept']]);

    livewire(EditWord::class, ['record' => $this->word->getKey()])
        ->assertFormSet(['confusable_with' => []])
        ->fillForm(['confusable_with' => ['  FATHER ', 'father']])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $this->word->fresh();
    expect($fresh->confusableWith())->toBe(['father'])
        ->and($fresh->meta)->toBe(['other' => 'kept', 'confusable_with' => ['father']]);

    livewire(EditWord::class, ['record' => $this->word->getKey()])
        ->assertFormSet(['confusable_with' => ['father']])
        ->fillForm(['confusable_with' => []])
        ->call('save');

    expect($this->word->fresh()->meta)->toBe(['other' => 'kept']);
});

it('rejects a confused word that is not in the course, a word of the same course is accepted', function (): void {
    livewire(EditWord::class, ['record' => $this->word->getKey()])
        ->fillForm(['confusable_with' => ['father', 'nonexistent']])
        ->call('save')
        ->assertHasFormErrors(['confusable_with']);

    expect($this->word->fresh()->confusableWith())->toBe([]);
});

it('does not accept a word of another course', function (): void {
    $other = Word::factory()->create(['word' => 'aunt']);

    expect(app(WordContentEditor::class)->unknownConfusable($this->word, ['aunt', 'father']))->toBe(['aunt']);
    expect($other->exists)->toBeTrue();
});

it('caps the confusable list at ten and leaves out the word itself', function (): void {
    $tags = ['Mother', ...array_map(static fn (int $i): string => "w{$i}", range(1, 14))];

    $list = app(WordContentEditor::class)->normalizeConfusable($this->word, $tags);

    expect($list)->toHaveCount(10)->and($list)->not->toContain('mother');
});
