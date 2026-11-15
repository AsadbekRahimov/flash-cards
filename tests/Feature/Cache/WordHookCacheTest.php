<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordHookResolver;
use App\Domain\Content\Services\WordTranslationWriter;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\RelationManagers\WordTranslationsRelationManager;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * `word:hook:v1:{word_id}` (docs/19 §3, docs/06 §12.3): the authored hooks of a word, every locale in one entry, TTL 2 h. Whoever
 * writes a hook must be read back at once - the key is dropped by the WordTranslation observer after every save / delete.
 */

beforeEach(function (): void {
    PureCache::store()->flush();
    $this->word = Word::factory()->create(['word' => 'mother', 'translation' => 'мать']);
    $this->resolver = app(WordHookResolver::class);
    $this->key = WordHookResolver::cacheKey($this->word->id);
});

/** The resolver as the training flow uses it: a word without loaded translations, so the entry of the cache is read. */
function hookOf(Word $word, string $locale): ?string
{
    return app(WordHookResolver::class)->for(Word::query()->findOrFail($word->id), $locale);
}

it('keeps the hooks of every locale in one array entry under a versioned key', function (): void {
    $writer = app(WordTranslationWriter::class);
    $writer->save($this->word, 'ru', 'мать', null, 'мать ~ mother');
    $writer->save($this->word, 'uz', 'ona', null, "ona - 'mother'");

    expect(hookOf($this->word, 'ru'))->toBe('мать ~ mother')
        ->and($this->key)->toBe("word:hook:v1:{$this->word->id}")
        ->and(PureCache::store()->get($this->key))->toBe(['ru' => 'мать ~ mother', 'uz' => "ona - 'mother'"]);
});

it('serves the new hook after WordTranslationWriter::save replaces the old one', function (): void {
    $writer = app(WordTranslationWriter::class);
    $writer->save($this->word, 'ru', 'мать', null, 'старый крючок');

    expect(hookOf($this->word, 'ru'))->toBe('старый крючок')
        ->and(PureCache::store()->has($this->key))->toBeTrue();

    $writer->save($this->word, 'ru', 'мать', null, 'новый крючок');

    expect(PureCache::store()->has($this->key))->toBeFalse()
        ->and(hookOf($this->word, 'ru'))->toBe('новый крючок');
});

it('serves null once the hook is cleared (a blank hook) or the translation row is deleted', function (): void {
    $writer = app(WordTranslationWriter::class);
    $writer->save($this->word, 'ru', 'мать', null, 'крючок');
    $writer->save($this->word, 'uz', 'ona', null, 'ona hook');
    expect(hookOf($this->word, 'ru'))->toBe('крючок');

    $writer->save($this->word, 'ru', 'мать', null, '   ');
    expect(hookOf($this->word, 'ru'))->toBeNull()
        ->and(hookOf($this->word, 'uz'))->toBe('ona hook');

    WordTranslation::query()->where('word_id', $this->word->id)->where('locale', 'uz')->firstOrFail()->delete();
    expect(hookOf($this->word, 'uz'))->toBeNull();
});

it('serves the hook an admin writes in the translations of the word at once, and null after it is removed', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $row = WordTranslation::factory()->create(['word_id' => $this->word->id, 'locale' => 'ru', 'translation' => 'мать', 'hook' => 'старый']);

    expect(hookOf($this->word, 'ru'))->toBe('старый');

    $manager = fn () => livewire(WordTranslationsRelationManager::class, ['ownerRecord' => $this->word, 'pageClass' => EditWord::class]);

    $manager()->callTableAction('edit', $row, data: ['translation' => 'мать', 'hook' => 'новый'])->assertHasNoTableActionErrors();
    expect(hookOf($this->word, 'ru'))->toBe('новый');

    $manager()->callTableAction('edit', $row->fresh(), data: ['translation' => 'мать', 'hook' => ''])->assertHasNoTableActionErrors();
    expect(hookOf($this->word, 'ru'))->toBeNull();

    $manager()->callTableAction('create', data: ['locale' => 'uz', 'translation' => 'ona', 'hook' => 'ona hook'])->assertHasNoTableActionErrors();
    expect(hookOf($this->word, 'uz'))->toBe('ona hook');
});
