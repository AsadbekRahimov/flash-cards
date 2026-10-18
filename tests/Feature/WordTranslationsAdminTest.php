<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordTranslationWriter;
use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Filament\Resources\WordResource;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\Pages\ListWords;
use App\Filament\Resources\WordResource\RelationManagers\WordTranslationsRelationManager;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->word = Word::factory()->create(['word' => 'hello', 'translation' => 'привет']);
    WordTranslation::factory()->create(['word_id' => $this->word->id, 'locale' => 'ru', 'translation' => 'привет']);
});

function translationsManager(Word $word): Testable
{
    return livewire(WordTranslationsRelationManager::class, ['ownerRecord' => $word, 'pageClass' => EditWord::class]);
}

it('adds a uz translation as a manual row', function (): void {
    $this->actingAs($this->admin);

    translationsManager($this->word)
        ->callTableAction('create', data: ['locale' => 'uz', 'translation' => 'salom', 'example_translation' => 'Salom, dunyo!'])
        ->assertHasNoTableActionErrors();

    $row = WordTranslation::query()->where('word_id', $this->word->id)->where('locale', 'uz')->sole();
    expect($row->translation)->toBe('salom')
        ->and($row->example_translation)->toBe('Salom, dunyo!')
        ->and($row->source)->toBe('manual')
        ->and($row->reviewed_at)->toBeNull();
});

it('allows one translation per language and word', function (): void {
    $this->actingAs($this->admin);

    translationsManager($this->word)
        ->callTableAction('create', data: ['locale' => 'ru', 'translation' => 'здравствуй'])
        ->assertHasTableActionErrors(['locale' => 'unique']);

    expect($this->word->translations()->count())->toBe(1);
});

it('rejects a locale the platform does not support', function (): void {
    $this->actingAs($this->admin);

    translationsManager($this->word)
        ->callTableAction('create', data: ['locale' => 'kk', 'translation' => 'сәлем'])
        ->assertHasTableActionErrors(['locale']);
});

it('keeps words.translation equal to the ru row when the ru row is edited', function (): void {
    $this->actingAs($this->admin);
    $ru = $this->word->translations()->where('locale', 'ru')->sole();

    translationsManager($this->word)
        ->callTableAction('edit', $ru, data: ['translation' => 'здравствуйте'])
        ->assertHasNoTableActionErrors();

    expect($this->word->fresh()->translation)->toBe('здравствуйте')
        ->and($ru->fresh()->translation)->toBe('здравствуйте')
        ->and($ru->fresh()->source)->toBe('manual');
});

it('does not touch words.translation when another language is edited', function (): void {
    $this->actingAs($this->admin);
    $uz = WordTranslation::factory()->uz()->create(['word_id' => $this->word->id, 'translation' => 'salom']);

    translationsManager($this->word)
        ->callTableAction('edit', $uz, data: ['translation' => 'assalom'])
        ->assertHasNoTableActionErrors();

    expect($this->word->fresh()->translation)->toBe('привет')
        ->and($uz->fresh()->translation)->toBe('assalom');
});

it('marks a translation as reviewed, and a later text change takes the mark away again', function (): void {
    $this->actingAs($this->admin);
    $uz = WordTranslation::factory()->uz()->create(['word_id' => $this->word->id, 'translation' => 'salom', 'source' => 'import']);

    translationsManager($this->word)
        ->assertTableActionVisible('markReviewed', $uz)
        ->callTableAction('markReviewed', $uz);

    expect($uz->fresh()->reviewed_at)->not->toBeNull();

    translationsManager($this->word)->assertTableActionHidden('markReviewed', $uz->fresh());

    // Same text again: still reviewed. New text: back to unreviewed.
    translationsManager($this->word)->callTableAction('edit', $uz->fresh(), data: ['translation' => 'salom']);
    expect($uz->fresh()->reviewed_at)->not->toBeNull();

    translationsManager($this->word)->callTableAction('edit', $uz->fresh(), data: ['translation' => 'salom!']);
    expect($uz->fresh()->reviewed_at)->toBeNull();
});

it('syncs the ru row when the word text is edited on the word screen', function (): void {
    $this->actingAs($this->admin);

    livewire(EditWord::class, ['record' => $this->word->getRouteKey()])
        ->fillForm(['translation' => 'приветствие'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->word->fresh()->translation)->toBe('приветствие')
        ->and($this->word->translations()->where('locale', 'ru')->value('translation'))->toBe('приветствие');
});

it('syncs the ru row when the word is edited on the lesson screen', function (): void {
    $this->actingAs($this->admin);
    $lesson = Lesson::query()->findOrFail($this->word->lesson_id);

    livewire(WordsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
        ->callTableAction('edit', $this->word, data: ['word' => 'hello', 'translation' => 'здорово'])
        ->assertHasNoTableActionErrors();

    expect($this->word->translations()->where('locale', 'ru')->value('translation'))->toBe('здорово');
});

it('shows teachers the word list but not the translation editor', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $this->actingAs($teacher);

    livewire(ListWords::class)->assertOk()->assertCanSeeTableRecords([$this->word]);

    $this->get(WordResource::getUrl('edit', ['record' => $this->word]))->assertForbidden();
});

it('writer: creates a missing ru row from words.translation without changing the word', function (): void {
    $word = Word::factory()->create(['translation' => 'вода']);

    app(WordTranslationWriter::class)->syncDefaultFromWord($word);

    expect($word->translations()->where('locale', 'ru')->value('translation'))->toBe('вода')
        ->and($word->fresh()->translation)->toBe('вода');
});

it('writer: refuses an unsupported locale', function (): void {
    expect(fn () => app(WordTranslationWriter::class)->save($this->word, 'kk', 'сәлем'))
        ->toThrow(InvalidArgumentException::class, "Unsupported locale 'kk'");
});
