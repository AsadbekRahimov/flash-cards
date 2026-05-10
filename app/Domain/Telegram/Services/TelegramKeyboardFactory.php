<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

final class TelegramKeyboardFactory
{
    /**
     * @return array{inline_keyboard:list<list<array{text:string,web_app:array{url:string}}>>}
     */
    public function webAppButton(string $text, string $url): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => $text,
                    'web_app' => ['url' => $url],
                ],
            ]],
        ];
    }
}
