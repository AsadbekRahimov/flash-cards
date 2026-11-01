<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;

/**
 * What PracticeItemFactory builds around the row of a task when it is created (not an attribute of the model, so it rides on
 * the factory): the stage of its version, the content, the audio status of a dictation, the target words, or nothing at all.
 */
final readonly class PracticeItemBuild
{
    /**
     * @param  array<string, mixed>  $content  the keys that replace the default content of the type
     * @param  list<int>  $words  ids of the target words
     */
    public function __construct(
        public bool $bare = false,
        public PracticeItemStatus $stage = PracticeItemStatus::Draft,
        public array $content = [],
        public ?AudioStatus $audio = null,
        public array $words = [],
    ) {}

    public function asBare(): self
    {
        return new self(true, $this->stage, $this->content, $this->audio, $this->words);
    }

    public function atStage(PracticeItemStatus $stage): self
    {
        return new self($this->bare, $stage, $this->content, $this->audio, $this->words);
    }

    /** @param array<string, mixed> $content */
    public function withContent(array $content): self
    {
        return new self($this->bare, $this->stage, $content, $this->audio, $this->words);
    }

    public function withAudio(AudioStatus $audio): self
    {
        return new self($this->bare, $this->stage, $this->content, $audio, $this->words);
    }

    /** @param list<int> $ids */
    public function withWords(array $ids): self
    {
        return new self($this->bare, $this->stage, $this->content, $this->audio, [...$this->words, ...$ids]);
    }
}
