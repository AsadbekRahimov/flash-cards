<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\Word;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the tests of the dictation (18.5) share: a learner who has been introduced to words with a voice on the disk of the words,
 * and published dictations with a voice on the disk `practice_audio`. The test fakes the disk `practice_audio` itself
 * (`Storage::fake('practice_audio')`); the disk of the words is faked for every test by Tests\TestCase.
 */
final class DictationKit
{
    /** @return array{0: Learner, 1: Course, 2: Lesson} */
    public static function world(string $locale = 'ru'): array
    {
        [$course, $lesson] = PracticeKit::course();

        return [Learner::factory()->create(['interface_locale' => $locale]), $course, $lesson];
    }

    /**
     * A word of the lesson the learner has been introduced to. `$voiced`: its `audio_path` is the file its current text hashes to;
     * `$file`: that file is on the disk of the words; `$introduced`: the learner has seen the word.
     */
    public static function word(Lesson $lesson, Learner $learner, string $text, string $translation = 'мама', bool $voiced = true, bool $file = true, bool $introduced = true): Word
    {
        $word = Word::factory()->for($lesson)->create([
            'word' => $text,
            'translation' => $translation,
            'audio_path' => $voiced ? app(WordAudioService::class)->pathFor($text) : null,
        ]);
        if ($introduced) {
            PracticeKit::introduce($learner, $word);
        }
        if ($voiced && $file) {
            Storage::disk('public')->put((string) $word->audio_path, 'mp3:'.$text);
        }

        return $word;
    }

    /**
     * A published dictation of the lesson with the audio in the state `$audio` (ready | pending | failed); its target word is one
     * the learner knows and that has no voice of its own, so it does not count as a word of the dictation. `$file`: the clip is on
     * the disk.
     *
     * @param  array<string, mixed>  $content  over the default content of a dictation
     */
    public static function phrase(Lesson $lesson, Learner $learner, string $text, array $content = [], string $audio = 'ready', bool $file = true): PracticeItem
    {
        $target = self::word($lesson, $learner, 'target-'.Str::lower(Str::random(8)), voiced: false);
        $factory = PracticeItem::factory()->dictation()->published()->forLesson($lesson)->targeting($target)
            ->withContent(['text' => $text, 'accepted' => [$text], ...$content]);
        $item = match ($audio) {
            'pending' => $factory->audioPending(),
            'failed' => $factory->audioFailed(),
            default => $factory->audioReady(),
        };
        $item = $item->create();

        if ($file && $audio === 'ready') {
            Storage::disk('practice_audio')->put((string) app(PracticeAudioService::class)->pathFor(self::version($item)), 'mp3:'.$text);
        }

        return $item;
    }

    /** The version the learners get. */
    public static function version(PracticeItem $item): PracticeItemVersion
    {
        return PracticeItemVersion::query()->findOrFail($item->published_version_id);
    }

    /** Phrases "Phrase number one", "...two" (no two alike) and words "wordone", "wordtwo"... for the learner, all voiced. */
    public static function stock(Lesson $lesson, Learner $learner, int $phrases, int $words): void
    {
        $names = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen'];
        for ($i = 0; $i < $phrases; $i++) {
            self::phrase($lesson, $learner, 'Phrase number '.$names[$i]);
        }
        for ($i = 0; $i < $words; $i++) {
            self::word($lesson, $learner, 'word'.$names[$i], 'слово '.$names[$i]);
        }
    }
}
