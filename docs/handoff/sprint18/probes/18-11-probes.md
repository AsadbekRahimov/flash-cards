# Пробы ревьюера к задаче 18.11 (повторное ревью соло-практики)

Pest-файлы, которые воспроизводили находки на e8b2ab0. Чтобы запустить: сохранить блок в файл `/tmp/probe/<Имя>.php` (ВНЕ `tests/`) и выполнить `./vendor/bin/pest /tmp/probe/<Имя>.php`. После исправлений 18.11 ожидания проб, закрепляющие ошибку, должны упасть — это и есть проверка.

## DictationNoItemsHubProbeTest.php

```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
});

it('tells the dictation card of a lesson with no tasks and two voiced words learned `no_items` (the TWA: "no tasks", no "Learn the lesson")', function (): void {
    [$learner, $course, $lesson] = DictationKit::world('ru');
    DictationKit::word($lesson, $learner, 'mother', 'мама');
    DictationKit::word($lesson, $learner, 'father', 'папа');
    // a third word of the lesson, voiced, not learned yet: learning it opens the mode
    DictationKit::word($lesson, $learner, 'sister', 'сестра', introduced: false);

    $hub = $this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk();
    $card = collect($hub->json('modes'))->firstWhere('mode', 'dictation');
    fwrite(STDERR, "\nhub dictation card: ".json_encode($card)."\n");

    DictationKit::word($lesson, $learner, 'brother', 'брат');   // one more word is learned
    $after = collect($this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->json('modes'))->firstWhere('mode', 'dictation');
    fwrite(STDERR, "after one more word: ".json_encode($after)."\n");

    expect($card['reason'])->toBe('no_items')->and($after['state'])->toBe('available');
});
```

## LexiconTypoProbeTest.php

```php
<?php

declare(strict_types=1);

use App\Domain\Practice\Services\Grading\DictationGrader;
use App\Domain\Practice\Support\EnglishLexicon;

uses(Tests\TestCase::class);

it('probes common learner misspellings one edit away from the expected word', function (): void {
    $g = app(DictationGrader::class);
    // [expected, typed] - the typed token is NOT an English word; one edit away (OSA 1).
    $cases = [
        // consonant doubling omitted
        ['stopped', 'stoped'], ['running', 'runing'], ['beginning', 'begining'], ['swimming', 'swiming'], ['getting', 'geting'],
        ['sitting', 'siting'], ['bigger', 'biger'], ['hotter', 'hoter'], ['planned', 'planed'], ['shopping', 'shoping'],
        ['occurred', 'occured'], ['preferred', 'prefered'], ['travelling', 'traveling'], ['putting', 'puting'], ['cutting', 'cuting'],
        ['dropped', 'droped'], ['chatting', 'chating'], ['stopping', 'stoping'], ['biggest', 'bigest'], ['hottest', 'hotest'],
        // silent e kept
        ['making', 'makeing'], ['writing', 'writeing'], ['coming', 'comeing'], ['having', 'haveing'], ['giving', 'giveing'],
        ['living', 'liveing'], ['taking', 'takeing'], ['using', 'useing'], ['dancing', 'danceing'], ['driving', 'driveing'],
        // consonant doubled wrongly
        ['meeting', 'meetting'], ['eating', 'eatting'], ['visited', 'visitted'], ['opened', 'openned'], ['looking', 'lookking'],
        // y-rules
        ['studies', 'studys'], ['families', 'familys'], ['cities', 'citys'], ['played', 'plaied'], ['happier', 'happyer'],
        // ordinary slips (expected to stay typo)
        ['because', 'becuase'], ['friend', 'freind'], ['receive', 'recieve'], ['tomorrow', 'tomorow'], ['beautiful', 'beatiful'],
        ['really', 'realy'], ['finally', 'finaly'], ['until', 'untill'], ['which', 'wich'], ['their', 'thier'],
        ['different', 'diferent'], ['address', 'adress'], ['necessary', 'neccessary'], ['always', 'allways'], ['coffee', 'cofee'],
        ['sister', 'sistr'], ['mother', 'mohter'], ['father', 'fahter'], ['brother', 'brothr'], ['family', 'famly'],
        ['school', 'scool'], ['teacher', 'teaher'], ['morning', 'mornig'], ['evening', 'evenig'], ['breakfast', 'brekfast'],
        ['wednesday', 'wensday'], ['february', 'febuary'], ['library', 'libary'], ['probably', 'probaly'], ['usually', 'usualy'],
    ];
    $out = [];
    $wrong = 0;
    foreach ($cases as [$expected, $answer]) {
        $verdict = $g->grade([$expected], $answer)->verdict->value;
        $isWord = EnglishLexicon::isWord($answer) ? 'word' : '-';
        $lemmas = implode(',', array_filter(EnglishLexicon::lemmas($answer), static fn (string $l): bool => EnglishLexicon::isWord($l)));
        if ($verdict === 'wrong') {
            $wrong++;
        }
        $out[] = sprintf('%-12s <- %-12s => %-6s %-5s %s', $expected, $answer, $verdict, $isWord, $lemmas);
    }
    fwrite(STDERR, "\n".implode("\n", $out)."\nwrong: {$wrong} of ".count($cases)."\n");
    expect(true)->toBeTrue();
});
```

## PilotPhraseSlipProbeTest.php

```php
<?php

declare(strict_types=1);

use App\Domain\Practice\Services\Grading\DictationGrader;

uses(Tests\TestCase::class);

it('probes slips in the phrases of the pilot pack', function (): void {
    $g = app(DictationGrader::class);
    $cases = [
        ['The meeting is at ten', 'The meting is at ten'], ['Good morning', 'Good mornig'], ['Have a nice evening', 'Have a nice evning'],
        ['This is my mother', 'This is my mothe'], ['Today is Friday', 'Today is Frida'], ['We meet on Sunday', 'We meet on Sunda'],
        ['We walk together', 'We walk togethe'], ['I have one sister', 'I have one siste'], ['I love my family', 'I love my famly'],
        ['Her brother is tall', 'Her brothr is tall'], ['My grandmother lives here', 'My grandmother livs here'],
    ];
    $out = [];
    foreach ($cases as [$expected, $answer]) {
        $out[] = sprintf('%-26s <- %-26s => %s', $expected, $answer, $g->grade([$expected], $answer)->verdict->value);
    }
    fwrite(STDERR, "\n".implode("\n", $out)."\n");
    expect(true)->toBeTrue();
});
```

## RefusedWordHtmlProbeTest.php

```php
<?php

declare(strict_types=1);

use App\Domain\Practice\Services\PracticeItemService;
use App\Filament\Resources\PracticeItemResource\Pages\ListPracticeItems;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

uses(Tests\TestCase::class, RefreshDatabase::class, Pest\Livewire\InteractsWithLivewire::class);

it('puts the text of a target word unescaped into the body of the refusal of «Опубликовать»', function (): void {
    Queue::fake();
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $admin = User::factory()->create(['is_active' => true, 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $course = Course::factory()->published()->create(['slug' => 'rw-html']);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    // A word text as the vocabulary import or the word form accept it (required|string|max:100).
    $word = Word::factory()->for($lesson)->create(['word' => '<a href="https://evil.example/x">fix</a><img src="https://evil.example/p.png">', 'translation' => 'т']);

    $item = app(PracticeItemService::class)->save(null, $course, ['type' => 'collocation', 'key' => 'rw1', 'level' => 'A1', 'word_ids' => [$word->id], 'content' => [
        'text' => "Let's ___ a meeting.", 'options' => ['have', 'make', 'do'], 'correct' => ['have'],
        'translation' => ['ru' => 'Давайте проведём встречу.', 'uz' => "Uchrashuv o'tkazaylik."], 'explanation' => ['ru' => 'Сочетание.', 'uz' => 'Birikma.'],
    ]], $admin)->item;
    app(PracticeItemService::class)->markReviewed($item, $admin);

    // The word is switched off before the draft is published (the refusal names it by its text since 18.9a).
    Word::query()->whereKey($word->id)->update(['is_active' => false]);

    $fresh = PracticeItem::query()->findOrFail($item->id);
    livewire(ListPracticeItems::class)->callTableAction('publish', $fresh, arguments: ['version' => $fresh->current_version_id]);

    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();
    $sent = $mailbox->pull();
    $body = (string) ($sent[0]['body'] ?? '');
    $rendered = (string) str($body)->sanitizeHtml();
    fwrite(STDERR, "\nbody: {$body}\nrendered: {$rendered}\n");

    expect($rendered)->toContain('<a href="https://evil.example/x"')->toContain('<img src="https://evil.example/p.png"');
});
```

## RegenerateReadyPublishedProbeTest.php

```php
<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Services\PracticeItemService;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\Learner;
use App\Models\PracticeItemVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Practice\PracticeKit;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('takes a READY published dictation away from the learners when the admin voices the failed draft again', function (): void {
    Queue::fake();
    $admin = User::factory()->create();
    [$course, $lesson] = PracticeKit::course('rg-audio');
    [$w] = PracticeKit::words($lesson, 1);
    $learner = Learner::factory()->create();
    PracticeKit::introduce($learner, $w);
    $data = fn (string $text): array => ['type' => 'dictation', 'key' => 'rg-d', 'word_ids' => [$w->id], 'content' => ['text' => $text, 'translation' => ['ru' => 'т', 'uz' => 't']]];

    $svc = app(PracticeItemService::class);
    $item = $svc->save(null, $course, $data('See you tomorrow'), $admin)->item;
    $svc->markReviewed($item, $admin);
    $svc->publish($item, $admin);
    $v1 = $item->fresh()->published_version_id;
    PracticeItemVersion::query()->whereKey($v1)->update(['audio_status' => AudioStatus::Ready->value, 'audio_key' => str_repeat('a', 40)]); // learners get v1

    $svc->save($item, $course, $data('See you tomorrow evening'), $admin); // a draft
    $v2 = $item->fresh()->current_version_id;
    PracticeItemVersion::query()->whereKey($v2)->update(['audio_status' => AudioStatus::Failed->value, 'audio_error' => 'tts_service_failed']); // its TTS failed

    $material = app(PracticeMaterialService::class);
    $before = count($material->eligibleItems(new MaterialScope($course), PracticeItemType::Dictation, $learner));

    Queue::fake();
    $svc->regenerateAudio($item->fresh());   // «Сгенерировать аудио заново» to fix the draft («Правка: ошибка»)

    $queued = [];
    Queue::assertPushed(GeneratePracticeAudioJob::class, function (GeneratePracticeAudioJob $job) use (&$queued): bool { $queued[] = [$job->versionId, $job->force]; return true; });
    $after = count(app(PracticeMaterialService::class)->eligibleItems(new MaterialScope($course), PracticeItemType::Dictation, $learner));

    fwrite(STDERR, "\n".json_encode([
        'eligible before' => $before,
        'queued [version, force]' => $queued,
        'v1 (published) status after the click' => PracticeItemVersion::query()->find($v1)->audio_status->value,
        'eligible after (until the job ends; for good if the TTS is still failing)' => $after,
    ])."\n");

    expect($before)->toBe(1)->and($after)->toBe(0);
});
```

## TailSlipProbeTest.php

```php
<?php

declare(strict_types=1);

use App\Domain\Practice\Services\Grading\DictationGrader;

uses(Tests\TestCase::class);

it('probes slips at the last letter of words that end in s, d, r, n, y', function (): void {
    $g = app(DictationGrader::class);
    $cases = [
        // a doubled last letter (a sticky key)
        ['mother', 'motherr'], ['father', 'fatherr'], ['dinner', 'dinnerr'], ['children', 'childrenn'], ['happy', 'happyy'],
        ['kitchen', 'kitchenn'], ['family', 'familyy'], ['garden', 'gardenn'], ['friend', 'friendd'], ['always', 'alwayss'],
        // the last letter hit by its neighbour, both in s/d/r/n/y
        ['children', 'childred'], ['kitchen', 'kitches'], ['garden', 'gardes'], ['water', 'wated'], ['doctor', 'doctod'],
        ['Monday', 'Mondan'], ['Sunday', 'Sundar'], ['brown', 'browd'], ['seven', 'seves'], ['woman', 'womas'],
        // the last letter dropped
        ['mother', 'mothe'], ['happy', 'happ'], ['kitchen', 'kitche'], ['dinner', 'dinne'], ['family', 'famil'],
    ];
    $out = [];
    foreach ($cases as [$expected, $answer]) {
        $out[] = sprintf('%-10s <- %-10s => %s', $expected, $answer, $g->grade([$expected], $answer)->verdict->value);
    }
    fwrite(STDERR, "\n".implode("\n", $out)."\n");
    expect(true)->toBeTrue();
});
```
