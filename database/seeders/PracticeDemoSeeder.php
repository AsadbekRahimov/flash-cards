<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Models\Course;
use App\Models\Learner;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\GuardsDemoSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The demo of the practice (docs/16 §15 task 18.7a, §16 item 1): the pilot package of the coordinator - 90 tasks in three lessons of
 * the demo course - imported and published, the demo learners in the pilot, and the audio of the phrases of the dictations.
 *
 *   - The demo learners (the members of the demo group) are put into the pilot (`learners.practice_pilot_at`), and introduced to the
 *     target words of the package the way the lessons of the group would have done it (a `word_repetitions` row of the group lesson,
 *     ten days old, not due): DemoRepetitionsSeeder covers the first forty words only, and a task is handed out to a learner who has
 *     been introduced to ALL its words. With `PRACTICE_ACCESS=pilot` the demo learners can practice at once.
 *   - The audio. Where no TTS service is configured (`twa.tts.service_url` is empty - the local environment) the seeder voices every
 *     phrase itself: `ffmpeg` with the `flite` filter makes an mp3 that is put under `{audio_key}.mp3` on the disk of the practice
 *     audio, and the version becomes `ready`. Where ffmpeg (or its flite filter) is missing the seeder says so and leaves the phrases
 *     `pending`. With a TTS service the queue (GeneratePracticeAudioJob) does it, as in production.
 *
 * Only in local/testing, like the other demo seeders. A repeated run changes nothing.
 */
class PracticeDemoSeeder extends Seeder
{
    use GuardsDemoSeeding;

    /** Under database/: the package is data, not code. */
    public const PACKAGE = 'data/practice/pilot-a1-a2.json';

    public function run(): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }

        $report = app(PracticeImporter::class)->importAsSystem((string) file_get_contents(database_path(self::PACKAGE)), publish: true);
        if (! $report->ok()) {
            throw new RuntimeException('The pilot package of the practice was refused: '.implode(' ', $report->errors));
        }

        $course = app(CourseCatalogService::class)->defaultCourse();

        $this->enrolDemoLearners($course);
        $this->voicePhrases($course);
    }

    /** The pilot and the words: the demo learners are the members of the demo group (as in DemoRepetitionsSeeder). */
    private function enrolDemoLearners(Course $course): void
    {
        $group = TelegramGroup::query()->where('chat_id', -1001234567890)->first();
        if ($group === null) {
            $this->demoWarning('PracticeDemoSeeder: no demo group, so no learner is put into the pilot of the practice (run DemoGroupSeeder and DemoLearnersSeeder first).');

            return;
        }

        $learnerIds = Learner::query()
            ->whereHas('memberships', fn ($q) => $q->where('telegram_group_id', $group->id)->where('status', 'active'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($learnerIds === []) {
            return;
        }

        Learner::query()->whereIn('id', $learnerIds)->whereNull('practice_pilot_at')->update(['practice_pilot_at' => now()]);

        $wordIds = PracticeItemWord::query()
            ->whereIn('item_id', PracticeItem::query()->where('course_id', $course->id)->select('id'))
            ->distinct()
            ->pluck('word_id')
            ->all();

        // The history of the group lessons: ten days old, so neither the new-word quota of today nor the cards due today change.
        $introduced = CarbonImmutable::now()->subDays(10);
        $rows = [];
        foreach ($learnerIds as $learnerId) {
            foreach ($wordIds as $wordId) {
                $rows[] = [
                    'learner_id' => $learnerId,
                    'word_id' => $wordId,
                    'repetitions' => 1,
                    'interval_days' => 3,
                    'easiness_factor' => 2.5,
                    'last_quality' => 4,
                    'last_reviewed_at' => $introduced,
                    'next_review_at' => CarbonImmutable::now()->addDays(3),
                    'is_hard' => false,
                    'introduced_mode' => 'group_lesson',
                    'state' => RepetitionState::Review->value,
                    'learning_step' => 0,
                    'lapses' => 0,
                    'meta' => '{}',
                    'created_at' => $introduced,
                    'updated_at' => $introduced,
                ];
            }
        }

        // The pair (learner, word) is unique: a row that is already there (the group's own history) stays as it is.
        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('word_repetitions')->insertOrIgnore($chunk);
            }
        });
    }

    /** The audio of the phrases of the published dictations, made here when no TTS service is configured. */
    private function voicePhrases(Course $course): void
    {
        if (filled(config('twa.tts.service_url'))) {
            return;
        }

        $diskName = (string) config('practice.audio.disk');
        try {
            $disk = Storage::disk($diskName);
        } catch (InvalidArgumentException) {
            $this->demoWarning("PracticeDemoSeeder: the disk '{$diskName}' is not configured, so the audio of the phrases stays pending.");

            return;
        }

        $versions = PracticeItemVersion::query()
            ->whereIn('id', PracticeItem::query()
                ->where('course_id', $course->id)
                ->where('type', PracticeItemType::Dictation->value)
                ->whereNotNull('published_version_id')
                ->select('published_version_id'))
            ->orderBy('id')
            ->get();

        foreach ($versions as $version) {
            $text = $version->content['text'] ?? null;
            if (! is_string($text) || $text === '') {
                continue;
            }

            $key = $this->audioKey($version, $text);
            $path = "{$key}.mp3";
            $voiced = $version->audio_status === AudioStatus::Ready && $version->audio_key === $key && $disk->exists($path) && $disk->size($path) > 0;
            if ($voiced) {
                continue;
            }

            $sound = $this->synthesize($text);
            if ($sound === null) {
                $this->demoWarning('PracticeDemoSeeder: ffmpeg with the flite filter is not available, so the audio of the phrases stays pending.');

                return;
            }

            $disk->put($path, $sound);
            PracticeItemVersion::query()->whereKey($version->id)->update([
                'audio_key' => $key,
                'audio_status' => AudioStatus::Ready->value,
                'audio_error' => null,
                'audio_generated_at' => now(),
            ]);
        }
    }

    /** The name of the file by docs/16 §10.1: `sha1(version_id|normalized text|voice|audio_version)`. */
    private function audioKey(PracticeItemVersion $version, string $text): string
    {
        return sha1(implode('|', [$version->id, PracticeTextNormalizer::normalize($text), (string) config('twa.tts.voice'), (string) config('twa.tts.audio_version')]));
    }

    /**
     * The mp3 of a phrase made by the speech of ffmpeg's flite filter; null if it cannot be made. The text goes through a file:
     * quotes, commas and colons of a phrase would need escaping in the filter graph.
     */
    private function synthesize(string $text): ?string
    {
        $input = tempnam(sys_get_temp_dir(), 'phrase');
        $output = tempnam(sys_get_temp_dir(), 'phrase');
        if ($input === false || $output === false) {
            return null;
        }

        try {
            file_put_contents($input, $text);

            $result = Process::timeout(60)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'lavfi', '-i', "flite=textfile={$input}",
                '-ac', '1', '-ar', '22050', '-codec:a', 'libmp3lame', '-b:a', '48k',
                '-f', 'mp3', $output,
            ]);

            $sound = $result->successful() ? file_get_contents($output) : false;

            return is_string($sound) && $sound !== '' ? $sound : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
