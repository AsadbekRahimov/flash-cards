<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordAudioService;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/*
 * The address of the word audio on the `public` disk (docs/31 §5д): `https://<main domain>/audio/<hash>.mp3`, served by nginx
 * (`location ^~ /audio/`), not the `/storage/audio/` of the disk. The other files of the disk keep `/storage`; the `audio`
 * disk (R2) builds its address itself, and with AUDIO_PUBLIC_URL = the main domain that is the same `/audio/` address.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.tts.voice', 'en-US-AriaNeural');
    Config::set('twa.tts.audio_version', 1);
    Config::set('twa.tts.audio_disk', 'public');
    Storage::fake('public');
});

function publicUrlPathOf(string $text): string
{
    return 'audio/'.sha1(mb_strtolower($text).'|en-US-AriaNeural|1').'.mp3';
}

function publicUrlWord(string $text, array $attrs = []): Word
{
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();

    return Word::factory()->for($lesson)->create(['word' => $text] + $attrs);
}

it('defaults the base of the word audio to APP_URL without a trailing slash', function (): void {
    expect(config('twa.tts.public_disk_audio_url'))->toBe(rtrim((string) config('app.url'), '/'));
});

it('gives a word on the public disk the /audio/ address of the main domain', function (): void {
    Config::set('twa.tts.public_disk_audio_url', 'https://muhrla.example');
    $path = publicUrlPathOf('resilient');
    $word = publicUrlWord('Resilient', ['audio_path' => $path, 'example' => 'She is resilient.', 'example_audio_path' => app(WordAudioService::class)->examplePathFor('She is resilient.')]);
    $service = app(WordAudioService::class);

    expect($service->urlFor($word))->toBe("https://muhrla.example/{$path}")
        ->and($service->urlFor($word))->toMatch('#\Ahttps://muhrla\.example/audio/[0-9a-f]{40}\.mp3\z#')
        ->and($service->exampleUrlFor($word, 'She is resilient.'))->toBe('https://muhrla.example/'.$word->example_audio_path);
});

it('keeps /storage for the other files of the public disk', function (): void {
    Config::set('twa.tts.public_disk_audio_url', 'https://muhrla.example');

    expect(Storage::disk('public')->url('avatars/a.png'))->toEndWith('/storage/avatars/a.png');
});

it('gives the old /storage/audio/ address back with a base of APP_URL/storage, and falls back to the disk without a base', function (string $base, string $expected): void {
    Config::set('twa.tts.public_disk_audio_url', $base);
    $path = publicUrlPathOf('resilient');

    expect(app(WordAudioService::class)->urlFor(publicUrlWord('resilient', ['audio_path' => $path])))->toBe(str_replace('{path}', $path, $expected));
})->with([
    'old address' => ['https://muhrla.example/storage', 'https://muhrla.example/storage/{path}'],
    'no base' => ['', '/storage/{path}'],
]);

it('still gives no address to a stale path', function (): void {
    Config::set('twa.tts.public_disk_audio_url', 'https://muhrla.example');
    $word = publicUrlWord('resilient', ['audio_path' => publicUrlPathOf('durable')]);

    expect(app(WordAudioService::class)->urlFor($word))->toBeNull();
});

it('does not use the base for the R2 disk: AUDIO_PUBLIC_URL set to the main domain gives the same /audio/ address', function (): void {
    Config::set('twa.tts.audio_disk', 'audio');
    Config::set('twa.tts.public_disk_audio_url', 'https://elsewhere.example');
    Storage::forgetDisk('audio');
    Config::set('filesystems.disks.audio', [...config('filesystems.disks.audio'), 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'url' => 'https://muhrla.example']);
    $path = publicUrlPathOf('resilient');

    expect(app(WordAudioService::class)->urlFor(publicUrlWord('resilient', ['audio_path' => $path])))->toBe("https://muhrla.example/{$path}");
});

it('links public/audio for a server without nginx (php artisan storage:link)', function (): void {
    expect(config('filesystems.links'))->toHaveKey(public_path('audio'))
        ->and(config('filesystems.links')[public_path('audio')])->toBe(storage_path('app/public/audio'))
        ->and(config('filesystems.links')[public_path('storage')])->toBe(storage_path('app/public'));
});
