<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * What `WebApp.shareToStory` needs (docs/40 §2.4, docs/04 A.26): the public HTTPS address of the 9:16 card, the caption
 * (≤ 200 characters, with the learner's invite link - a story carries `widget_link` only for Telegram Premium) and the widget link
 * itself: the same invite link under the name of the brand.
 */
final readonly class StoryShare
{
    public function __construct(
        public string $mediaUrl,
        public string $text,
        public string $widgetUrl,
        public string $widgetName,
    ) {}
}
