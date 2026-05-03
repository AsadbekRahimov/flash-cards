<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TtsFallbackService
{
    /**
     * @return array{body:string, content_type:string}
     */
    public function synthesize(string $word, ?string $voice = null): array
    {
        $baseUrl = rtrim((string) config('twa.tts.service_url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('tts_service_not_configured');
        }

        $response = Http::timeout((int) config('twa.tts.timeout', 8))
            ->accept('audio/mpeg')
            ->post($baseUrl.'/tts', array_filter([
                'word' => $word,
                'voice' => $voice,
            ], fn ($value): bool => $value !== null && $value !== ''));

        if (! $response->successful()) {
            throw new RuntimeException('tts_service_failed');
        }

        return [
            'body' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'audio/mpeg',
        ];
    }
}
