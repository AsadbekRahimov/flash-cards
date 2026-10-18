<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordTranslationResolver;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function wordWithTranslations(array $byLocale, string $default = 'по-умолчанию'): Word
{
    $word = Word::factory()->create(['translation' => $default]);
    foreach ($byLocale as $locale => $text) {
        WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => $locale, 'translation' => $text]);
    }

    return $word;
}

it('returns the translation of the requested locale without a fallback flag', function (): void {
    $word = wordWithTranslations(['ru' => 'привет', 'uz' => 'salom']);

    $resolved = app(WordTranslationResolver::class)->resolve($word, 'uz');

    expect($resolved->text)->toBe('salom')
        ->and($resolved->locale)->toBe('uz')
        ->and($resolved->isFallback)->toBeFalse()
        ->and(app(WordTranslationResolver::class)->for($word, 'uz'))->toBe('salom');
});

it('falls back from uz to the ru row and flags it', function (): void {
    $word = wordWithTranslations(['ru' => 'привет']);

    $resolved = app(WordTranslationResolver::class)->resolve($word, 'uz');

    expect($resolved->text)->toBe('привет')
        ->and($resolved->locale)->toBe('ru')
        ->and($resolved->isFallback)->toBeTrue();
});

it('falls back to words.translation when no translation row exists at all', function (): void {
    $word = wordWithTranslations([], 'слово');

    $uz = app(WordTranslationResolver::class)->resolve($word, 'uz');
    $ru = app(WordTranslationResolver::class)->resolve($word, 'ru');

    expect($uz->text)->toBe('слово')->and($uz->isFallback)->toBeTrue()
        // ru is the default language: reading words.translation for ru is not a "fallback".
        ->and($ru->text)->toBe('слово')->and($ru->isFallback)->toBeFalse();
});

it('treats an empty translation row as missing', function (): void {
    $word = wordWithTranslations(['ru' => 'привет']);
    WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'uz', 'translation' => '  ']);

    expect(app(WordTranslationResolver::class)->resolve($word, 'uz')->isFallback)->toBeTrue();
});

it('treats an unsupported locale like a missing one', function (): void {
    $word = wordWithTranslations(['ru' => 'привет']);

    $resolved = app(WordTranslationResolver::class)->resolve($word, 'kk');

    expect($resolved->text)->toBe('привет')->and($resolved->isFallback)->toBeTrue();
});

it('uses an already loaded translations relation without querying', function (): void {
    $word = wordWithTranslations(['ru' => 'привет', 'uz' => 'salom']);
    $word->load('translations');

    DB::enableQueryLog();
    $text = app(WordTranslationResolver::class)->for($word, 'uz');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($text)->toBe('salom')->and($queries)->toBeEmpty();
});

it('resolves a batch with one query', function (): void {
    $a = wordWithTranslations(['ru' => 'а', 'uz' => 'a-uz']);
    $b = wordWithTranslations(['ru' => 'б']);
    $c = wordWithTranslations([], 'в');

    DB::enableQueryLog();
    $map = app(WordTranslationResolver::class)->map(collect([$a, $b, $c]), 'uz');
    $many = app(WordTranslationResolver::class)->resolveMany([$a, $b, $c], 'uz');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($map)->toBe([$a->id => 'a-uz', $b->id => 'б', $c->id => 'в'])
        ->and($many[$a->id]->isFallback)->toBeFalse()
        ->and($many[$b->id]->isFallback)->toBeTrue()
        ->and($many[$c->id]->isFallback)->toBeTrue()
        ->and($queries)->toHaveCount(2); // one per call, not per word
});

it('returns an empty map for no words without querying', function (): void {
    DB::enableQueryLog();
    $map = app(WordTranslationResolver::class)->map([], 'uz');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($map)->toBe([])->and($queries)->toBeEmpty();
});
