<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\VersionState;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A version of a task with a VALID `content` for its type (docs/16 §2.3, §4.5) - what the tests of the modes
 * use instead of PracticeItemService. The type states set the content; a dictation also gets an `audio_key`
 * and the audio status `pending` ({@see audioReady()} and the like change it).
 *
 * The place and the target words of the version (`lesson_id`, `level`, `target_word_ids`) are those the task has at the moment
 * (its columns and its `practice_item_words`), so a version agrees with its task unless a test says otherwise.
 *
 * @extends Factory<PracticeItemVersion>
 */
class PracticeItemVersionFactory extends Factory
{
    /** Samples cycle by a counter, so two tasks made one after the other differ. */
    private const SAMPLES = 6;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            // A task without a version of its own: this factory writes the version.
            'item_id' => PracticeItem::factory()->bare(),
            'number' => 1,
            'state' => VersionState::Draft,
            'content' => self::contentFor(PracticeItemType::Collocation, $counter),
            // After `item_id`: a closure is given the attributes resolved so far.
            'target_word_ids' => static fn (array $attributes): array => self::wordsOf($attributes['item_id']),
            'lesson_id' => static fn (array $attributes): ?int => self::lessonOf($attributes['item_id']),
            'level' => static fn (array $attributes): ?string => self::levelOf($attributes['item_id']),
            'audio_key' => null,
            'audio_status' => AudioStatus::None,
            'audio_error' => null,
            'audio_generated_at' => null,
            'created_by_user_id' => null,
            'reviewed_by_user_id' => null,
            'published_by_user_id' => null,
            'reviewed_at' => null,
            'published_at' => null,
            'superseded_at' => null,
        ];
    }

    public function collocation(): static
    {
        return $this->ofType(PracticeItemType::Collocation);
    }

    public function sentence(): static
    {
        return $this->ofType(PracticeItemType::Sentence);
    }

    public function dictation(): static
    {
        return $this->ofType(PracticeItemType::Dictation);
    }

    public function reviewed(): static
    {
        return $this->state(fn (): array => ['state' => VersionState::Reviewed, 'reviewed_at' => now()]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'state' => VersionState::Published,
            'reviewed_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn (): array => [
            'state' => VersionState::Superseded,
            'reviewed_at' => now(),
            'published_at' => now(),
            'superseded_at' => now(),
        ]);
    }

    /** The dictation is voiced: the file `{audio_key}.mp3` is expected on the audio disk. */
    public function audioReady(): static
    {
        return $this->state(fn (array $attributes): array => [
            'audio_key' => $attributes['audio_key'] ?? self::audioKey(),
            'audio_status' => AudioStatus::Ready,
            'audio_generated_at' => now(),
        ]);
    }

    public function audioPending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'audio_key' => $attributes['audio_key'] ?? self::audioKey(),
            'audio_status' => AudioStatus::Pending,
            'audio_generated_at' => null,
        ]);
    }

    public function audioFailed(string $error = 'tts_failed'): static
    {
        return $this->state(fn (array $attributes): array => [
            'audio_key' => $attributes['audio_key'] ?? self::audioKey(),
            'audio_status' => AudioStatus::Failed,
            'audio_error' => $error,
        ]);
    }

    /**
     * Takes the keys of `$content` over the default content of the type (a key is replaced as a whole).
     *
     * @param  array<string, mixed>  $content
     */
    public function withContent(array $content): static
    {
        return $this->state(fn (array $attributes): array => [
            'content' => array_replace((array) $attributes['content'], $content),
        ]);
    }

    /**
     * A valid `content` of the type; `$n` picks one of the samples (cycling). The same shapes as docs/16 §2.3, so
     * the texts pass the validation of §4.5: three distinct options, a permutation of all the fragments, ru + uz.
     *
     * @return array<string, mixed>
     */
    public static function contentFor(PracticeItemType $type, int $n = 0): array
    {
        $i = $n % self::SAMPLES;

        return match ($type) {
            PracticeItemType::Sentence => self::sentenceContent($i),
            PracticeItemType::Collocation => self::collocationContent($i),
            PracticeItemType::Dictation => self::dictationContent($i),
        };
    }

    /** @return list<int> the target words the task has now, ascending */
    private static function wordsOf(mixed $itemId): array
    {
        $ids = [];
        foreach (PracticeItemWord::query()->where('item_id', $itemId)->orderBy('word_id')->pluck('word_id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    private static function lessonOf(mixed $itemId): ?int
    {
        $lesson = PracticeItem::query()->whereKey($itemId)->value('lesson_id');

        return is_numeric($lesson) ? (int) $lesson : null;
    }

    private static function levelOf(mixed $itemId): ?string
    {
        $level = PracticeItem::query()->whereKey($itemId)->value('level');

        return is_string($level) ? $level : null;
    }

    /** The name of the audio file of a version: 40 hex characters, like `sha1(...)`. */
    public static function audioKey(): string
    {
        return sha1(uniqid('practice-audio', true));
    }

    private function ofType(PracticeItemType $type): static
    {
        static $counter = 0;
        $counter++;

        return $this->state(fn (): array => [
            'item_id' => PracticeItem::factory()->bare()->state(['type' => $type]),
            'content' => self::contentFor($type, $counter),
            'audio_key' => $type === PracticeItemType::Dictation ? self::audioKey() : null,
            'audio_status' => $type === PracticeItemType::Dictation ? AudioStatus::Pending : AudioStatus::None,
        ]);
    }

    /** @return array<string, mixed> */
    private static function sentenceContent(int $i): array
    {
        $samples = [
            ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi.", 'fragments' => ['My', 'mother', 'is', 'a', 'teacher'], 'accepted' => [[0, 1, 2, 3, 4]], 'display' => 'My mother is a teacher.'],
            ['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.', 'fragments' => ['I', 'usually', 'drink', 'tea'], 'accepted' => [[0, 1, 2, 3], [1, 0, 2, 3]], 'display' => 'I usually drink tea.'],
            ['ru' => 'У неё два брата.', 'uz' => 'Uning ikkita akasi bor.', 'fragments' => ['She', 'has', 'two', 'brothers'], 'accepted' => [[0, 1, 2, 3]], 'display' => 'She has two brothers.'],
            ['ru' => 'Мы ходим в школу пешком.', 'uz' => 'Biz maktabga piyoda boramiz.', 'fragments' => ['We', 'walk', 'to', 'school'], 'accepted' => [[0, 1, 2, 3]], 'display' => 'We walk to school.'],
            ['ru' => 'Он работает в банке.', 'uz' => 'U bankda ishlaydi.', 'fragments' => ['He', 'works', 'in', 'a', 'bank'], 'accepted' => [[0, 1, 2, 3, 4]], 'display' => 'He works in a bank.'],
            ['ru' => 'Они живут в Ташкенте.', 'uz' => 'Ular Toshkentda yashaydi.', 'fragments' => ['They', 'live', 'in', 'Tashkent'], 'accepted' => [[0, 1, 2, 3]], 'display' => 'They live in Tashkent.'],
        ];
        $s = $samples[$i];

        return [
            'prompt' => ['ru' => $s['ru'], 'uz' => $s['uz']],
            'fragments' => $s['fragments'],
            'accepted' => $s['accepted'],
            'display' => $s['display'],
            'explanation' => ['ru' => 'Порядок слов в английском фиксированный.', 'uz' => "Ingliz tilida so'z tartibi qat'iy."],
        ];
    }

    /** @return array<string, mixed> */
    private static function collocationContent(int $i): array
    {
        $samples = [
            ['text' => "Let's ___ a meeting on Monday.", 'options' => ['have', 'make', 'do'], 'correct' => [0], 'ru' => 'Давайте проведём встречу в понедельник.', 'uz' => 'Dushanba kuni uchrashuv o\'tkazaylik.'],
            ['text' => 'She wants to ___ a break.', 'options' => ['take', 'make', 'do'], 'correct' => [0], 'ru' => 'Она хочет сделать перерыв.', 'uz' => 'U tanaffus qilmoqchi.'],
            ['text' => 'Please ___ attention to the teacher.', 'options' => ['pay', 'make', 'do'], 'correct' => [0], 'ru' => 'Пожалуйста, слушайте учителя внимательно.', 'uz' => "Iltimos, o'qituvchiga e'tibor bering."],
            ['text' => 'He is good ___ math.', 'options' => ['at', 'in', 'on'], 'correct' => [0], 'ru' => 'Он силён в математике.', 'uz' => 'U matematikadan kuchli.'],
            ['text' => 'They ___ a party yesterday.', 'options' => ['had', 'made', 'did'], 'correct' => [0], 'ru' => 'Вчера они устроили вечеринку.', 'uz' => "Kecha ular ziyofat o'tkazdi."],
            ['text' => "I'm interested ___ music.", 'options' => ['in', 'on', 'at'], 'correct' => [0], 'ru' => 'Я интересуюсь музыкой.', 'uz' => 'Men musiqaga qiziqaman.'],
        ];
        $s = $samples[$i];

        return [
            'text' => $s['text'],
            'options' => $s['options'],
            'correct' => $s['correct'],
            'translation' => ['ru' => $s['ru'], 'uz' => $s['uz']],
            'explanation' => ['ru' => 'Устойчивое сочетание: его нужно запомнить целиком.', 'uz' => "Turg'un birikma: uni butunligicha eslab qoling."],
        ];
    }

    /** @return array<string, mixed> */
    private static function dictationContent(int $i): array
    {
        $samples = [
            ['text' => 'See you tomorrow', 'ru' => 'До завтра', 'uz' => 'Ertagacha'],
            ['text' => 'Nice to meet you', 'ru' => 'Приятно познакомиться', 'uz' => 'Tanishganimdan xursandman'],
            ['text' => 'How much is it', 'ru' => 'Сколько это стоит', 'uz' => 'Bu qancha turadi'],
            ['text' => 'Where is the station', 'ru' => 'Где вокзал', 'uz' => 'Vokzal qayerda'],
            ['text' => 'I would like some tea', 'ru' => 'Я бы хотел чаю', 'uz' => 'Men choy ichgim keldi'],
            ['text' => 'Thank you very much', 'ru' => 'Большое спасибо', 'uz' => 'Katta rahmat'],
        ];
        $s = $samples[$i];

        return [
            'text' => $s['text'],
            'accepted' => [$s['text']],
            'translation' => ['ru' => $s['ru'], 'uz' => $s['uz']],
            'explanation' => null,
        ];
    }
}
