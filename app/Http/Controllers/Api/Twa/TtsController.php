<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Twa\Services\TtsFallbackService;
use App\Http\Resources\ApiErrorResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TtsController
{
    public function __construct(
        private readonly TtsFallbackService $tts,
        private readonly WordAudioService $audio,
    ) {}

    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z][A-Za-z\s\'-]*\z/u'],
            'voice' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9-]+\z/'],
        ]);

        // Pre-generated file (task 1.2c): no call to the TTS service.
        $stored = $this->audio->storedPath($data['word'], $data['voice'] ?? null);
        $bytes = $stored !== null ? $this->audio->read($stored) : null;
        if ($bytes !== null) {
            return response($bytes, 200)
                ->header('Content-Type', 'audio/mpeg')
                ->header('Cache-Control', 'public, max-age=2592000');
        }

        try {
            $synth = $this->tts->synthesize($data['word'], $data['voice'] ?? null);
        } catch (RuntimeException|ConnectionException) {
            return ApiErrorResource::respond(503, 'tts_unavailable', 'TTS fallback service is unavailable.');
        }

        return response($synth['body'], 200)
            ->header('Content-Type', $synth['content_type'])
            ->header('Cache-Control', 'public, max-age=2592000');
    }
}
