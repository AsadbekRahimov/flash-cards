<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordAudioService;
use App\Models\Course;
use App\Models\Word;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Gives the words of the first four lessons of the demo course what two kinds of the game's tasks need (the stand of the browser e2e,
 * docs/05 §5b.4): an example with the word in it (`gap`) and a recording (`listen`). The demo seed has neither, so a run would never
 * show these two tasks and the pictures of them could not be taken.
 *
 *   php resources/twa/scripts/e2e/game-seed.php [file to list the clips made]
 *
 * The recordings are made with ffmpeg and its flite filter, as `PracticeDemoSeeder` does for the dictations, and put where
 * `WordAudioService::pathFor` says; the list of what was made goes to the file, so that `stand.sh down` removes exactly those clips.
 * It runs in the environment of the stand and changes the database of the stand and nothing else. No ffmpeg: only the examples are made.
 */
$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$course = Course::query()->where('slug', 'default')->firstOrFail();
$lessons = $course->stages()->orderBy('number')->get()
    ->flatMap(static fn ($stage) => $stage->lessons()->orderBy('number')->pluck('id'))
    ->take(4)
    ->all();

$audio = $app->make(WordAudioService::class);
$disk = Storage::disk((string) config('twa.tts.audio_disk', 'public'));
$made = [];
$examples = 0;

foreach (Word::query()->whereIn('lesson_id', $lessons)->where('is_active', true)->orderBy('id')->get() as $word) {
    $update = [];
    if (trim((string) $word->example) === '') {
        $update['example'] = "I say {$word->word} every day.";
        $examples++;
    }

    $path = $audio->pathFor($word->word);
    if ($path !== null) {
        if (! $disk->exists($path) || $disk->size($path) === 0) {
            $input = tempnam(sys_get_temp_dir(), 'word');
            $output = tempnam(sys_get_temp_dir(), 'word');
            file_put_contents((string) $input, $word->word);
            $result = Process::timeout(60)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'lavfi', '-i', "flite=textfile={$input}",
                '-ac', '1', '-ar', '22050', '-codec:a', 'libmp3lame', '-b:a', '48k',
                '-f', 'mp3', (string) $output,
            ]);
            $sound = $result->successful() ? file_get_contents((string) $output) : false;
            @unlink((string) $input);
            @unlink((string) $output);
            if (is_string($sound) && $sound !== '') {
                $disk->put($path, $sound);
                $made[] = $path;
            }
        }
        if ($disk->exists($path) && $disk->size($path) > 0) {
            $update['audio_path'] = $path;
        }
    }

    if ($update !== []) {
        Word::query()->whereKey($word->id)->update($update);
    }
}

if (isset($argv[1]) && $argv[1] !== '') {
    file_put_contents($argv[1], implode("\n", $made).($made === [] ? '' : "\n"));
}

fwrite(STDOUT, sprintf("game seed: %d examples, %d recordings made (%s)\n", $examples, count($made), $disk->path('') ?: 'disk'));
