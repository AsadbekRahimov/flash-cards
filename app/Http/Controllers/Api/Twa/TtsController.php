<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Content\Services\WordAudioService;
use App\Http\Resources\ApiErrorResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/twa/tts?word=` (docs/26 §9.4): the pre-generated file of the text, if any - a 302 to the URL of the audio disk,
 * else 404 `tts_not_found` (the Mini App then keeps the phone voice). Nothing is synthesized on behalf of a learner: an open
 * "voice any word" endpoint in front of a paid synthesizer would spend someone else's money. Only the configured voice has
 * files, so there is no `voice` parameter.
 */
final class TtsController
{
    public function __construct(private readonly WordAudioService $audio) {}

    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z][A-Za-z\s\'-]*\z/u'],
        ]);

        $path = $this->audio->storedPath($data['word']);
        if ($path === null) {
            return ApiErrorResource::respond(404, 'tts_not_found', 'No pre-generated audio for this word.')
                ->header('Cache-Control', 'no-store');
        }

        $url = Storage::disk((string) config('twa.tts.audio_disk', 'public'))->url($path);

        return redirect()->away($url, 302)->header('Cache-Control', 'private, max-age=3600');
    }
}
