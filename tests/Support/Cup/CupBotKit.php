<?php

declare(strict_types=1);

namespace Tests\Support\Cup;

use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\TelegramGroup;

/**
 * Updates of the bot of the cup (docs/33 §6, K6) for the tests: a tap on a `cup:*` button and a command in a group, sent through the
 * real dispatcher; a chat member who is not an administrator.
 */
final class CupBotKit
{
    private static int $updateId = 9_000_000;

    /**
     * A `callback_query` tapped by `$fromId` on the message `$messageId` of the group.
     *
     * @param  array<string, mixed>  $message  merged into the message (e.g. `is_topic_message`, another `chat`)
     * @return array<string, mixed>
     */
    public static function tap(TelegramGroup $group, string $data, int $fromId, int $messageId = 1, array $message = [], string $language = 'ru'): array
    {
        self::$updateId++;

        return [
            'update_id' => self::$updateId,
            'callback_query' => [
                'id' => 'cq-'.self::$updateId,
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Tapper', 'language_code' => $language],
                'chat_instance' => '-42',
                'data' => $data,
                'message' => array_replace([
                    'message_id' => $messageId,
                    'date' => 1_764_000_000,
                    'chat' => ['id' => $group->chat_id, 'type' => 'supergroup', 'title' => $group->title],
                ], $message),
            ],
        ];
    }

    /**
     * A text message (a command) of `$fromId` in the group.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function say(TelegramGroup $group, string $text, int $fromId, array $message = []): array
    {
        self::$updateId++;

        return [
            'update_id' => self::$updateId,
            'message' => array_replace([
                'message_id' => self::$updateId,
                'date' => 1_764_000_000,
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Asker'],
                'chat' => ['id' => $group->chat_id, 'type' => 'supergroup', 'title' => $group->title],
                'text' => $text,
            ], $message),
        ];
    }

    /** @param array<string, mixed> $update */
    public static function dispatch(array $update): void
    {
        app(TelegramDispatcher::class)->dispatch($update);
    }

    /** A member of the chat who is not an administrator. */
    public static function plainMember(int $telegramUserId = 777_000_222): int
    {
        CupKit::telegram()->chatMembersByUser[$telegramUserId] = new ChatMemberInfo('member', true);

        return $telegramUserId;
    }

    /**
     * The answer the tap got: its text and whether it was an alert (null - no text).
     *
     * @param  array<string, mixed>  $update
     * @return array{text: string, show_alert: bool}|null
     */
    public static function answerOf(array $update): ?array
    {
        $id = (string) $update['callback_query']['id'];
        expect(CupKit::telegram()->answered)->toContain($id);

        return CupKit::telegram()->answeredTexts[$id] ?? null;
    }

    /** The draft of the group proposed by `cup:new` of `$fromId` (its proposal is posted by the sync queue). */
    public static function propose(TelegramGroup $group, int $fromId = 777_000_333): Cup
    {
        self::dispatch(self::tap($group, 'cup:new', $fromId));

        return Cup::query()->with('telegramGroup')->where('telegram_group_id', $group->id)->latest('id')->firstOrFail();
    }

    public static function card(Cup $cup): CupMessage
    {
        return CupMessage::query()->where('cup_id', $cup->id)->where('dedupe_key', 'card')->firstOrFail();
    }

    /**
     * The buttons of a reply markup, flattened: `text => callback_data | url`.
     *
     * @param  array<string, mixed>|null  $markup
     * @return array<string, string>
     */
    public static function buttons(?array $markup): array
    {
        $buttons = [];
        foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
            foreach ((array) $row as $button) {
                $buttons[(string) $button['text']] = (string) ($button['callback_data'] ?? $button['url'] ?? $button['web_app']['url'] ?? '');
            }
        }

        return $buttons;
    }
}
