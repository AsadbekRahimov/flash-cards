<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\StoryShare;
use Illuminate\Http\Request;

/**
 * POST /api/twa/share/story → `{media_url, text, widget_link: {url, name}}` (docs/40 §2.4, docs/04 A.26): the arguments of
 * `WebApp.shareToStory(media_url, {text, widget_link})`. No `data` envelope.
 *
 * @property StoryShare $resource
 */
final class StoryShareResource extends TwaResource
{
    /** @return array{media_url: string, text: string, widget_link: array{url: string, name: string}} */
    public function toArray(Request $request): array
    {
        return [
            'media_url' => $this->resource->mediaUrl,
            'text' => $this->resource->text,
            'widget_link' => [
                'url' => $this->resource->widgetUrl,
                'name' => $this->resource->widgetName,
            ],
        ];
    }
}
