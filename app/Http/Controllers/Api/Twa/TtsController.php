<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Twa\Services\TtsFallbackService;
use App\Http\Resources\ApiErrorResource;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TtsController
{
    public function __construct(private readonly TtsFallbackService $tts) {}

    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z][A-Za-z\s\'-]*\z/u'],
            'voice' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9-]+\z/'],
        ]);

        try {
            $audio = $this->tts->synthesize($data['word'], $data['voice'] ?? null);
        } catch (RuntimeException) {
            return ApiErrorResource::respond(503, 'tts_unavailable', 'TTS fallback service is unavailable.');
        }

        return response($audio['body'], 200)
            ->header('Content-Type', $audio['content_type'])
            ->header('Cache-Control', 'public, max-age=2592000');
    }
}
