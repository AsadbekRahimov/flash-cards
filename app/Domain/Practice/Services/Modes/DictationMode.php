<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\DictationGrader;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;
use App\Models\PracticeItemVersion;

/**
 * "Listen and write" (docs/16 §4.3, §5.2, §7.2, §10): the learner hears a word or a phrase, writes it, and the server tells
 * "right", "a typo" or "to be corrected" ({@see DictationGrader}). The text is NEVER sent to the client before the answer: the
 * element it sees is the URL of the clip, the number of words and the swaps left.
 *
 * Material. Phrases: the published dictations of the scope, voiced (`audio_status = ready`), whose target words the learner has
 * been introduced to (`PracticeMaterialService::eligibleItems`); the clip is `{audio_key}.mp3` on the private disk
 * `practice_audio`. Words (solo only): the introduced words whose `audio_path` is the file their CURRENT text hashes to
 * (`WordAudioService::pathFor` - after an edit of the word the old file is not the word's voice); the clip is on the word disk. The
 * files of the elements that are taken are looked at (`exists && size > 0`, at most one look each); a missing one is skipped for the
 * next candidate. Nothing is read from the database here beyond what the material service remembers for the scope.
 *
 * Composition (§4.3). Solo: up to `practice.solo.dictation.phrases_max` phrases, the rest of the `size` words; short of words,
 * more phrases; the words come first ("first words, then phrases", docs/15 §3.4). A battle (`$learner = null`): `size` phrases and
 * no words. The candidates the session did not use go to `pool` ({@see spares()}) for the swap of a clip that does not play.
 *
 * An element of the snapshot (docs/16 §2.8), the same for a phrase and a word: `source` (`phrase` | `word`), `item_id` /
 * `version_id` (a phrase) or `word_id` (a word) - the retry reads them -, `audio_disk` + `audio_path`, `display` (the text as it
 * is written), `expected` (the normalized accepted variants, `display` first), `words` (how many), `translation` and `explanation`
 * (locale => text, for the locales of the set; the explanation is null for a word and for a phrase that has none).
 *
 * Battle: points of a round = 100 for `correct`, 0 for a typo (the verdict `typo` is the "typo" mark of the review), a mistake and a
 * skip. The reveal of `grade()` is in the language of the first locale of the snapshot (solo: the learner's own); a battle that
 * shows the review to both players reads `translation` / `explanation` of the snapshot for the viewer's locale.
 */
final class DictationMode implements PracticeModeHandler, ReplaceableElements
{
    public const SOURCE_PHRASE = 'phrase';

    public const SOURCE_WORD = 'word';

    /** Spare phrases and spare words the snapshot keeps for the swaps of a session (at most `max_replacements` are allowed; a few more for files that turn out missing). */
    private const SPARES_PER_SOURCE = 4;

    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly PracticeAudioService $audio,
        private readonly WordAudioService $wordAudio,
        private readonly DictationGrader $grader,
    ) {}

    public function mode(): PracticeMode
    {
        return PracticeMode::Dictation;
    }

    /**
     * Phrases + voiced words (a battle: phrases only). No disk, no query beyond the shared reads of the scope. When there is not
     * enough, the reason is the closest of docs/16 §4.3: `audio_pending` (tasks wait for their voice), `items_locked` (voiced
     * phrases are there but their words are not learned yet), `no_audio_words` (the learner has words, none of them is voiced),
     * `no_words` (nothing was introduced), else `no_items`.
     */
    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        $required = $learner === null ? max(1, (int) config('practice.battle.rounds')) : $this->material->minItems($scope);

        $phrases = count($this->phraseCandidates($scope, $learner, $locales));
        $introduced = $learner === null ? 0 : count($this->material->introducedWords($scope, $learner, $locales));
        $voiced = $learner === null ? 0 : count($this->wordCandidates($scope, $learner, $locales));
        $pending = $this->material->pendingAudioItems($scope, $learner);

        $available = $phrases + $voiced;
        if ($available >= $required) {
            return ModeAvailability::enough($available, $required);
        }

        $locked = $learner === null ? 0 : $this->material->lockedItems($scope, PracticeItemType::Dictation, $learner);
        $reason = match (true) {
            $pending > 0 && $available + $pending >= $required => ModeAvailability::REASON_AUDIO_PENDING,
            $locked > 0 && $available + $locked >= $required => ModeAvailability::REASON_ITEMS_LOCKED,
            $learner === null => ModeAvailability::REASON_NO_ITEMS,
            $introduced > 0 && $voiced === 0 => ModeAvailability::REASON_NO_AUDIO_WORDS,
            $introduced === 0 && $phrases === 0 => ModeAvailability::REASON_NO_WORDS,
            default => ModeAvailability::REASON_NO_ITEMS,
        };

        return ModeAvailability::notEnough($available, $required, $reason);
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $phrases = SeededShuffle::apply($this->phraseCandidates($scope, $learner, $locales), $seed, 'dictation:phrases');
        $words = $learner === null ? [] : SeededShuffle::apply($this->wordCandidates($scope, $learner, $locales), $seed, 'dictation:words');

        $phrasesWanted = $learner === null ? $size : min($size, max(0, (int) config('practice.solo.dictation.phrases_max')));
        $pickedPhrases = $this->takePlayable($phrases, $phrasesWanted);
        $pickedWords = $this->takePlayable($words, $size - count($pickedPhrases));
        $short = $size - count($pickedPhrases) - count($pickedWords);
        if ($short > 0) {
            $pickedPhrases = [...$pickedPhrases, ...$this->takePlayable($phrases, $short)];
        }

        $count = count($pickedPhrases) + count($pickedWords);
        $required = $strictSize ? $size : min($size, $this->material->minItems($scope));
        if ($count < $required) {
            // The material was there on paper (availability) and the files were not: the voice is not ready.
            $report = $this->availability($scope, $learner, $locales);

            throw PracticeException::notEnoughMaterial(
                $this->mode(),
                $report->isAvailable ? ModeAvailability::notEnough($count, $required, ModeAvailability::REASON_AUDIO_PENDING) : $report,
                $scope->course->slug,
                $scope->lesson?->id,
            );
        }

        $taken = [];
        $elements = [];
        foreach ([...$pickedWords, ...$pickedPhrases] as $data) {
            $id = OpaqueId::make('e', $taken);
            $taken[] = $id;
            $elements[] = new SnapshotElement($id, $this->mode()->elementKind(), $data);
        }

        return $elements;
    }

    /**
     * What the client sees (docs/16 §7.2): no text, no letters - the URL of the clip, the number of words (empty tiles) and the
     * swaps left. `audio_url` and `replacements_left` are the service's (the handler cannot know them).
     */
    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        return [
            'id' => $element->id,
            'kind' => $element->kind,
            'source' => $element->get('source'),
            'audio_url' => $state->audioUrl,
            'words' => (int) $element->get('words', 1),
            'replacements_left' => $state->replacementsLeft,
        ];
    }

    /**
     * `{text}` (the text may be empty: nothing written is a skip) or `{skip: true}`; anything else is 422 `invalid_answer_shape`
     * (`text_or_skip`). The text is not shortened here: the request has cut it at 200 characters.
     */
    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        $skip = ($answer['skip'] ?? null) === true;
        $text = $answer['text'] ?? null;
        if (! $skip && (! array_key_exists('text', $answer) || ($text !== null && ! is_string($text)))) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_TEXT_OR_SKIP);
        }

        $graded = $this->grader->grade($this->variants($element), $skip ? '' : (string) $text);

        return new GradeResult(
            verdict: $graded->verdict,
            reveal: [
                ...($graded->reveal ?? []),
                'translation' => $this->firstText($element->get('translation')),
                'explanation' => $this->firstText($element->get('explanation')),
            ],
            normalizedAnswer: $graded->normalizedAnswer,
            final: true,
            points: $graded->points,
            firstAttempt: true,
        );
    }

    /**
     * The review for a viewer of `$locale`: what was dictated (as it is written), its translation and explanation in that
     * language - from the element the answers were graded on. The text leaves the server only here, once the round is resolved.
     *
     * @return array{expected: string, translation: string|null, explanation: string|null}
     */
    public function reveal(SnapshotElement $element, string $locale, ElementState $state): array
    {
        return [
            'expected' => $this->variants($element)[0] ?? '',
            'translation' => $this->textFor($element->get('translation'), $locale),
            'explanation' => $this->textFor($element->get('explanation'), $locale),
        ];
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        $typed = $state->answer['text'] ?? null;

        return [[
            'label' => (string) $element->get('display'),
            'your_answer' => is_string($typed) && trim($typed) !== '' ? $typed : null,
        ]];
    }

    /**
     * The candidates the session did not take, in seeded order: a few phrases and a few words, each WITH everything an element
     * needs (a swap reads nothing from the database during the game, docs/16 §0). A battle never swaps: no pool.
     */
    public function spares(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, array $chosen): array
    {
        if ($learner === null) {
            return [];
        }

        $versions = [];
        $wordIds = [];
        foreach ($chosen as $element) {
            $versions[] = $element->get('version_id');
            $wordIds[] = $element->get('word_id');
        }

        $phrases = $this->untaken(SeededShuffle::apply($this->phraseCandidates($scope, $learner, $locales), $seed, 'dictation:phrases'), 'version_id', $versions);
        $words = $this->untaken(SeededShuffle::apply($this->wordCandidates($scope, $learner, $locales), $seed, 'dictation:words'), 'word_id', $wordIds);

        return [...array_slice($phrases, 0, self::SPARES_PER_SOURCE), ...array_slice($words, 0, self::SPARES_PER_SOURCE)];
    }

    /**
     * The first spare of the same source whose clip is on the disk, else the first spare of the other source (docs/16 §10.4). A
     * spare found missing is dropped from the pool along with the one used.
     */
    public function playableSpares(array $pool): array
    {
        $keys = [];
        foreach ($pool as $candidate) {
            if ($this->isPlayable($candidate)) {
                $keys[] = self::spareKey($candidate);
            }
        }

        return $keys;
    }

    public function replacement(SnapshotElement $replaced, array $pool, LocaleSet $locales, ?array $playable = null): ?array
    {
        $source = $replaced->get('source');
        $order = array_keys($pool);
        usort($order, static fn (int $a, int $b): int => [($pool[$a]['source'] ?? null) === $source ? 0 : 1, $a] <=> [($pool[$b]['source'] ?? null) === $source ? 0 : 1, $b]);

        $tried = [];
        foreach ($order as $index) {
            $tried[$index] = true;
            // The keys found before the lock decide; only without them is the disk looked at here.
            if ($playable === null ? $this->isPlayable($pool[$index]) : in_array(self::spareKey($pool[$index]), $playable, true)) {
                $rest = array_values(array_diff_key($pool, $tried));

                return [new SnapshotElement(OpaqueId::make('e', [$replaced->id]), $this->mode()->elementKind(), $pool[$index]), $rest];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ the material

    /**
     * The phrases of the scope as the data of an element: published, voiced, their target words introduced (a battle: only active),
     * the same text only once. In the order of the material (by the id of the task).
     *
     * @return list<array<string, mixed>>
     */
    private function phraseCandidates(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): array
    {
        return $scope->remember('dictation:phrases:'.($learner->id ?? 0).':'.implode(',', $locales->locales), function () use ($scope, $learner, $locales): array {
            $candidates = [];
            $seen = [];
            foreach ($this->material->eligibleItems($scope, PracticeItemType::Dictation, $learner) as $version) {
                $data = $this->phraseData($version, $locales);
                if ($data === null || isset($seen[$this->textKey($data)])) {
                    continue;
                }
                $seen[$this->textKey($data)] = true;
                $candidates[] = $data;
            }

            return $candidates;
        });
    }

    /**
     * The introduced words that have a voice of their current text, as the data of an element; the same text only once.
     *
     * @return list<array<string, mixed>>
     */
    private function wordCandidates(MaterialScope $scope, Learner $learner, LocaleSet $locales): array
    {
        return $scope->remember('dictation:words:'.$learner->id.':'.implode(',', $locales->locales), function () use ($scope, $learner, $locales): array {
            $candidates = [];
            $seen = [];
            foreach ($this->material->introducedWords($scope, $learner, $locales) as $word) {
                $path = $this->wordAudio->pathFor($word->word);
                // No voice yet, or the voice of an older text of the word: it is not this word's recording.
                if ($path === null || $word->audioPath !== $path) {
                    continue;
                }
                $data = $this->wordData($word, $path, $locales);
                if ($data === null || isset($seen[$this->textKey($data)])) {
                    continue;
                }
                $seen[$this->textKey($data)] = true;
                $candidates[] = $data;
            }

            return $candidates;
        });
    }

    /** @return array<string, mixed>|null null: the content has no usable text or the version has no file name */
    private function phraseData(PracticeItemVersion $version, LocaleSet $locales): ?array
    {
        $content = $version->content;
        $text = is_string($content['text'] ?? null) ? trim($content['text']) : '';
        $path = $this->audio->pathFor($version);
        if ($text === '' || $path === null) {
            return null;
        }

        $variants = [$text];
        foreach ((array) ($content['accepted'] ?? []) as $variant) {
            if (is_string($variant)) {
                $variants[] = $variant;
            }
        }
        $expected = $this->normalizedVariants($variants);
        if ($expected === []) {
            return null;
        }

        return [
            'source' => self::SOURCE_PHRASE,
            'item_id' => $version->item_id,
            'version_id' => $version->id,
            'word_id' => null,
            'audio_disk' => $this->audio->diskName(),
            'audio_path' => $path,
            'display' => $text,
            'expected' => $expected,
            'words' => max(1, count(PracticeTextNormalizer::tokens($text))),
            'translation' => $this->localized($content['translation'] ?? null, $locales),
            'explanation' => $this->localized($content['explanation'] ?? null, $locales) ?: null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function wordData(MaterialWord $word, string $path, LocaleSet $locales): ?array
    {
        $text = trim($word->word);
        $expected = $this->normalizedVariants([$text]);
        if ($expected === []) {
            return null;
        }

        $translation = [];
        foreach ($locales->locales as $locale) {
            $shown = trim($word->translationFor($locale));
            if ($shown !== '') {
                $translation[$locale] = $shown;
            }
        }

        return [
            'source' => self::SOURCE_WORD,
            'item_id' => null,
            'version_id' => null,
            'word_id' => $word->id,
            'audio_disk' => $this->audio->wordDiskName(),
            'audio_path' => $path,
            'display' => $text,
            'expected' => $expected,
            'words' => max(1, count(PracticeTextNormalizer::tokens($text))),
            'translation' => $translation,
            'explanation' => null,
        ];
    }

    /**
     * What makes two candidates the same text: the normalized text as it is written.
     *
     * @param  array<string, mixed>  $data
     */
    private function textKey(array $data): string
    {
        return PracticeTextNormalizer::normalize((string) ($data['display'] ?? ''));
    }

    /**
     * @param  list<string>  $variants
     * @return list<string> normalized, without repeats and empty ones; the first stays first
     */
    private function normalizedVariants(array $variants): array
    {
        $expected = [];
        foreach ($variants as $variant) {
            $normalized = PracticeTextNormalizer::normalize($variant);
            if ($normalized !== '' && ! in_array($normalized, $expected, true)) {
                $expected[] = $normalized;
            }
        }

        return $expected;
    }

    /**
     * The text of each locale of the set: the locale's own, else the Russian one, else any (docs/16 §4.4: the fallback of the
     * translations). Empty when the content has none.
     *
     * @return array<string, string>
     */
    private function localized(mixed $texts, LocaleSet $locales): array
    {
        if (! is_array($texts)) {
            return [];
        }

        $fallback = null;
        foreach (['ru', ...array_keys($texts)] as $key) {
            if (is_string($texts[$key] ?? null) && trim($texts[$key]) !== '') {
                $fallback = trim($texts[$key]);
                break;
            }
        }

        $result = [];
        foreach ($locales->locales as $locale) {
            $own = is_string($texts[$locale] ?? null) ? trim($texts[$locale]) : '';
            $shown = $own !== '' ? $own : $fallback;
            if ($shown !== null) {
                $result[$locale] = $shown;
            }
        }

        return $result;
    }

    /** The text of the locale from a snapshot map, else the first one the map has; null for nothing. */
    private function textFor(mixed $texts, string $locale): ?string
    {
        $own = is_array($texts) ? ($texts[$locale] ?? null) : null;

        return is_string($own) && $own !== '' ? $own : $this->firstText($texts);
    }

    /** The text of the first locale of a snapshot map (solo: the only one); null for nothing. */
    private function firstText(mixed $texts): ?string
    {
        foreach (is_array($texts) ? $texts : [] as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * What the grader compares with: the text as it is written (the review shows it), then the other accepted variants.
     *
     * @return list<string>
     */
    private function variants(SnapshotElement $element): array
    {
        $expected = array_values(array_filter((array) $element->get('expected'), 'is_string'));
        $display = trim((string) $element->get('display'));

        return [$display !== '' ? $display : ($expected[0] ?? ''), ...array_slice($expected, 1)];
    }

    // ------------------------------------------------------------------ the files

    /**
     * The candidates whose `$key` (`version_id` / `word_id`) is not among `$taken`.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<mixed>  $taken
     * @return list<array<string, mixed>>
     */
    private function untaken(array $candidates, string $key, array $taken): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => ! in_array($candidate[$key] ?? null, $taken, true),
        ));
    }

    /**
     * The first `$limit` candidates whose clip is on the disk, taken off the front of the list (a missing one is dropped).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function takePlayable(array &$candidates, int $limit): array
    {
        $taken = [];
        while (count($taken) < $limit && $candidates !== []) {
            $candidate = array_shift($candidates);
            if ($this->isPlayable($candidate)) {
                $taken[] = $candidate;
            }
        }

        return $taken;
    }

    /**
     * What names a spare: the disk and the path of its clip (a phrase and a word never share a file).
     *
     * @param  array<string, mixed>  $candidate
     */
    private static function spareKey(array $candidate): string
    {
        return (is_string($candidate['audio_disk'] ?? null) ? $candidate['audio_disk'] : '').'|'.(is_string($candidate['audio_path'] ?? null) ? $candidate['audio_path'] : '');
    }

    /** @param array<string, mixed> $candidate */
    private function isPlayable(array $candidate): bool
    {
        $disk = $candidate['audio_disk'] ?? null;
        $path = $candidate['audio_path'] ?? null;

        return is_string($disk) && is_string($path) && $path !== '' && $this->audio->hasFile($disk, $path);
    }
}
