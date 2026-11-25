<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Learners\Support\Regions;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollEditException;
use App\Domain\Polls\Support\PollAudience;
use App\Models\Course;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The form of a poll in `/admin` (docs/35 §1.5 "Создание", O6): the one place that writes `polls` and `poll_options` from it.
 *
 * What may change depends on the status, checked under the poll's row lock (`polls FOR UPDATE`, the lock of
 * {@see PollLauncher::launch()} too), so a form opened on a draft and saved after someone launched it is refused:
 *  - `draft` - everything;
 *  - `sending` / `sent` - the internal title and the deadline only; the broadcast reads the question, the options, the picture,
 *    the answer rules and the audience in every batch, so any of them in the data with a value other than the stored one is
 *    refused ({@see PollEditException::LOCKED}) - not only hidden by the form;
 *  - `closed` / `cancelled` - the title only ({@see PollEditException::FINISHED}).
 *
 * The options of a draft keep their ids by position (labels updated in place, extra positions added or removed), so a test vote
 * of the administrator stays attached to its option. A new picture drops `image_file_id` (Telegram's id of the old one); the replaced
 * file is deleted after the commit. The view of the poll in the bot ({@see PollViewCache}) is forgotten after every commit.
 *
 * The picture is a file the form stored on the private disk ({@see IMAGE_DISK}, `polls/<40 random characters>.jpg|png`, the name
 * never from the uploader): the form checks the type and the size of the upload, this checks the stored file again (path, type by
 * its content, size).
 */
final class PollEditor
{
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'polls';

    public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public const QUESTION_MAX = 700;

    public const LABEL_MAX = 40;

    public const TITLE_MAX = 120;

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 10;

    /** The fields that no longer change after the launch. */
    private const LOCKED = ['question', 'options', 'image_path', 'allows_multiple', 'max_choices', 'allow_change', 'audience'];

    public function __construct(private readonly PollViewCache $views) {}

    /**
     * @param  array<string, mixed>  $data  the form: title, question{ru,uz}, options[{label{ru,uz}}], image_path, allows_multiple,
     *                                      max_choices, allow_change, closes_at, audience{plan, locales, course_ids, region_codes, onboarded_only}
     *
     * @throws PollEditException
     */
    public function create(array $data, User $by, ?CarbonImmutable $now = null): Poll
    {
        $now ??= CarbonImmutable::now();
        $values = $this->draftValues($data, $now);

        return DB::transaction(function () use ($values, $by): Poll {
            $poll = Poll::query()->create([...$values['poll'], 'status' => PollStatus::Draft, 'created_by_user_id' => $by->id]);
            $this->syncOptions($poll, $values['options']);

            return $poll;
        });
    }

    /**
     * @param  array<string, mixed>  $data  as in {@see create()}; after the launch only `title` and `closes_at` may differ
     *
     * @throws PollEditException
     */
    public function update(Poll $poll, array $data, ?CarbonImmutable $now = null): Poll
    {
        $now ??= CarbonImmutable::now();
        $replaced = null;

        DB::transaction(function () use ($poll, $data, $now, &$replaced): void {
            /** @var Poll $locked */
            $locked = Poll::query()->whereKey($poll->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PollStatus::Draft) {
                $values = $this->draftValues($data, $now, $locked);
                if ($values['poll']['image_path'] !== $locked->image_path) {
                    $values['poll']['image_file_id'] = null;
                    $replaced = $locked->image_path;
                }

                $locked->forceFill($values['poll'])->save();
                $this->syncOptions($locked, $values['options']);

                return;
            }

            $this->assertUnchanged($locked, $data);
            $changes = ['title' => $this->title($data['title'] ?? $locked->title)];

            if (array_key_exists('closes_at', $data)) {
                $closesAt = $this->closesAt($data['closes_at'], $now, $locked->closes_at);
                if (! self::sameMoment($closesAt, $locked->closes_at)) {
                    if (in_array($locked->status, [PollStatus::Closed, PollStatus::Cancelled], true)) {
                        throw PollEditException::because(PollEditException::FINISHED);
                    }
                    $changes['closes_at'] = $closesAt;
                }
            }

            $locked->forceFill($changes)->save();
        });

        DB::afterCommit(function () use ($poll, $replaced): void {
            $this->views->forget($poll->id);
            if (is_string($replaced) && $replaced !== '') {
                Storage::disk(self::IMAGE_DISK)->delete($replaced);
            }
        });

        return $poll->refresh();
    }

    /** A draft goes with its options and test deliveries (cascade) and its picture. */
    public function delete(Poll $poll): bool
    {
        $image = null;
        $deleted = DB::transaction(function () use ($poll, &$image): bool {
            /** @var Poll|null $locked */
            $locked = Poll::query()->whereKey($poll->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== PollStatus::Draft) {
                return false;
            }

            $image = $locked->image_path;

            return (bool) $locked->delete();
        });

        if ($deleted) {
            $this->views->forget($poll->id);
            if (is_string($image) && $image !== '') {
                Storage::disk(self::IMAGE_DISK)->delete($image);
            }
        }

        return $deleted;
    }

    /**
     * Every field of a draft, checked.
     *
     * @param  array<string, mixed>  $data
     * @return array{poll: array<string, mixed>, options: list<array{ru: string, uz: string}>}
     */
    private function draftValues(array $data, CarbonImmutable $now, ?Poll $current = null): array
    {
        $options = $this->options($data['options'] ?? []);
        $multiple = (bool) ($data['allows_multiple'] ?? false);

        return [
            'poll' => [
                'title' => $this->title($data['title'] ?? ''),
                'question' => $this->question($data['question'] ?? null),
                'image_path' => $this->image($data['image_path'] ?? null, $current?->image_path),
                'allows_multiple' => $multiple,
                'max_choices' => $multiple ? $this->maxChoices($data['max_choices'] ?? null, count($options)) : null,
                'allow_change' => (bool) ($data['allow_change'] ?? true),
                'closes_at' => $this->closesAt($data['closes_at'] ?? null, $now, $current?->closes_at),
                'audience' => $this->audience($data['audience'] ?? null),
            ],
            'options' => $options,
        ];
    }

    /**
     * Refuses a field of a launched poll that would change (the form does not send them; a crafted request might).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertUnchanged(Poll $poll, array $data): void
    {
        foreach (self::LOCKED as $field) {
            // Absent, or the empty container of disabled nested fields the form leaves behind (`question: []`, `audience: []`).
            if (! array_key_exists($field, $data) || $data[$field] === []) {
                continue;
            }

            $same = match ($field) {
                'question' => self::texts($data['question']) === self::texts($poll->question),
                'options' => array_map(self::texts(...), array_column(self::rows($data['options']), 'label'))
                    === array_map(static fn (PollOption $o): array => self::texts($o->label), $poll->options()->get()->all()),
                'image_path' => ($data['image_path'] ?: null) === $poll->image_path,
                'allows_multiple' => (bool) $data['allows_multiple'] === $poll->allows_multiple,
                'max_choices' => ($data['max_choices'] === null || $data['max_choices'] === '' ? null : (int) $data['max_choices']) === $poll->max_choices,
                'allow_change' => (bool) $data['allow_change'] === $poll->allow_change,
                'audience' => PollAudience::from(is_array($data['audience']) ? $data['audience'] : null)->toArray() === PollAudience::from($poll->audience)->toArray(),
            };

            if (! $same) {
                throw PollEditException::because(in_array($poll->status, [PollStatus::Closed, PollStatus::Cancelled], true)
                    ? PollEditException::FINISHED
                    : PollEditException::LOCKED);
            }
        }
    }

    private function title(mixed $title): string
    {
        $title = trim(is_string($title) ? $title : '');
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            throw PollEditException::because(PollEditException::TITLE);
        }

        return $title;
    }

    /** @return array{ru: string, uz: string} */
    private function question(mixed $question): array
    {
        $texts = self::texts($question);
        foreach ($texts as $text) {
            if ($text === '' || mb_strlen($text) > self::QUESTION_MAX) {
                throw PollEditException::because(PollEditException::QUESTION);
            }
        }

        return $texts;
    }

    /** @return list<array{ru: string, uz: string}> */
    private function options(mixed $options): array
    {
        $rows = self::rows($options);
        if (count($rows) < self::MIN_OPTIONS || count($rows) > self::MAX_OPTIONS) {
            throw PollEditException::because(PollEditException::OPTIONS);
        }

        $labels = [];
        foreach ($rows as $row) {
            $label = self::texts($row['label'] ?? null);
            foreach ($label as $text) {
                if ($text === '' || mb_strlen($text) > self::LABEL_MAX) {
                    throw PollEditException::because(PollEditException::LABEL);
                }
            }
            $labels[] = $label;
        }

        return $labels;
    }

    private function maxChoices(mixed $value, int $options): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $max = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        if ($max < 1 || $max > $options) {
            throw PollEditException::because(PollEditException::MAX_CHOICES);
        }

        return $max;
    }

    private function image(mixed $path, ?string $current): ?string
    {
        if (is_array($path)) {
            $path = array_values($path)[0] ?? null;
        }

        if ($path === null || $path === '') {
            return null;
        }

        if (! is_string($path)) {
            throw PollEditException::because(PollEditException::IMAGE);
        }

        if ($path === $current) {
            return $path;
        }

        $disk = Storage::disk(self::IMAGE_DISK);
        $pattern = '~\A'.self::IMAGE_DIRECTORY.'/[A-Za-z0-9]{40}\.(jpg|png)\z~';
        $maxBytes = max(1, (int) config('polls.image_max_kb', 5120)) * 1024;

        if (preg_match($pattern, $path, $m) !== 1 || ! $disk->exists($path)) {
            throw PollEditException::because(PollEditException::IMAGE);
        }

        $type = $disk->mimeType($path);
        if (! is_string($type) || (self::IMAGE_TYPES[$type] ?? null) !== $m[1] || $disk->size($path) > $maxBytes) {
            throw PollEditException::because(PollEditException::IMAGE);
        }

        return $path;
    }

    private function closesAt(mixed $value, CarbonImmutable $now, ?DateTimeInterface $current): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $at = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw PollEditException::because(PollEditException::CLOSES_AT);
        }

        $at = $at->utc()->startOfMinute();

        // An unchanged deadline is kept as it is, even when it has passed (a poll the closing command is about to close).
        if (self::sameMoment($at, $current)) {
            return $at;
        }

        if ($at->lessThanOrEqualTo($now)) {
            throw PollEditException::because(PollEditException::CLOSES_AT);
        }

        return $at;
    }

    /** @return array<string, mixed> */
    private function audience(mixed $audience): array
    {
        $raw = is_array($audience) ? $audience : [];
        $normalized = PollAudience::from($raw);

        $given = static fn (string $key): int => is_array($raw[$key] ?? null) ? count(array_filter($raw[$key], static fn ($v): bool => $v !== null && $v !== '')) : 0;

        if (count($normalized->locales) !== $given('locales') || array_diff($normalized->locales, ['ru', 'uz']) !== []
            || count($normalized->regionCodes) !== $given('region_codes') || array_filter($normalized->regionCodes, static fn (string $code): bool => ! Regions::isValid($code)) !== []
            || count($normalized->courseIds) !== $given('course_ids')
            || ($normalized->courseIds !== [] && Course::query()->whereIn('id', $normalized->courseIds)->count() !== count($normalized->courseIds))
            || (isset($raw['plan']) && $raw['plan'] !== $normalized->plan)
        ) {
            throw PollEditException::because(PollEditException::AUDIENCE);
        }

        return $normalized->toArray();
    }

    /** @param list<array{ru: string, uz: string}> $labels */
    private function syncOptions(Poll $poll, array $labels): void
    {
        $existing = PollOption::query()->where('poll_id', $poll->id)->get()->keyBy('position');

        foreach ($labels as $position => $label) {
            $option = $existing->get($position);
            if ($option === null) {
                PollOption::query()->create(['poll_id' => $poll->id, 'position' => $position, 'label' => $label]);
            } elseif (self::texts($option->label) !== $label) {
                $option->forceFill(['label' => $label])->save();
            }
        }

        PollOption::query()->where('poll_id', $poll->id)->where('position', '>=', count($labels))->delete();
    }

    /** @return array{ru: string, uz: string} */
    private static function texts(mixed $texts): array
    {
        $texts = is_array($texts) ? $texts : [];

        return [
            'ru' => trim(is_string($texts['ru'] ?? null) ? $texts['ru'] : ''),
            'uz' => trim(is_string($texts['uz'] ?? null) ? $texts['uz'] : ''),
        ];
    }

    /** @return list<array<array-key, mixed>> the rows of a repeater (its keys are random) */
    private static function rows(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    private static function sameMoment(?DateTimeInterface $a, ?DateTimeInterface $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return CarbonImmutable::instance($a)->startOfMinute()->equalTo(CarbonImmutable::instance($b)->startOfMinute());
    }
}
