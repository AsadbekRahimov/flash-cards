# 06. Spaced Repetition — Алгоритм SM-2

## 1. Кратко об алгоритме

SM-2 — классический алгоритм интервальных повторений (SuperMemo 2, Piotr Woźniak, 1987). Для каждой карточки хранятся:
- `repetitions` — количество успешных повторений подряд (сброс при провале).
- `interval` — текущий интервал в днях до следующего показа.
- `easiness_factor` (EF) — коэффициент лёгкости, от 1.3 до ~2.5+.

Мы используем **упрощённый** вариант: 3 кнопки вместо 6-балльной шкалы.

## 2. Маппинг оценок студента

| Кнопка в UI | Quality (0..5) | Семантика |
|-------------|----------------|-----------|
| 😰 Трудно | 2 | Вспомнил с трудом или неправильно |
| 🙂 Средне | 3 | Вспомнил с паузой, но правильно |
| 😎 Легко | 5 | Моментально и уверенно |

На v1.1 можно добавить промежуточные 4 (без значка) — если будет запрос.

## 3. Формулы SM-2

### 3.1 Обновление EF
```
EF' = EF + (0.1 − (5 − q) · (0.08 + (5 − q) · 0.02))
EF' = max(1.3, EF')
```

### 3.2 Обновление interval и repetitions

```
if q < 3:
    repetitions = 0
    interval = 1           // показать завтра
    is_hard = true
else:
    if repetitions == 0:
        interval = 1
    elif repetitions == 1:
        interval = 6
    else:
        interval = round(interval * EF')
    repetitions += 1
    is_hard = (q == 2)     // "с трудом" — флаг для бустинга частоты
```

### 3.3 next_review_at
```
next_review_at = now() + interval * 1 day
```

В UI ранее показываем «трудные» — если `is_hard = true`, кладём в очередь выборки перед обычными, даже если `next_review_at` одинаковые.

## 4. PHP-реализация (Service)

```php
<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Models\WordRepetition;
use Carbon\CarbonImmutable;

final class SpacedRepetitionEngine
{
    public const MIN_EF = 1.30;
    public const DEFAULT_EF = 2.50;

    public function applyReview(WordRepetition $rep, int $quality, ?CarbonImmutable $now = null): WordRepetition
    {
        $this->assertQuality($quality);
        $now ??= CarbonImmutable::now();

        $ef = $this->newEasinessFactor((float) $rep->easiness_factor, $quality);

        if ($quality < 3) {
            $repetitions = 0;
            $interval = 1;
            $isHard = true;
        } else {
            $repetitions = $rep->repetitions + 1;
            $interval = match (true) {
                $rep->repetitions === 0 => 1,
                $rep->repetitions === 1 => 6,
                default => (int) round($rep->interval_days * $ef),
            };
            $isHard = $quality === 2;
        }

        $rep->easiness_factor = round($ef, 2);
        $rep->repetitions = $repetitions;
        $rep->interval_days = $interval;
        $rep->last_quality = $quality;
        $rep->last_reviewed_at = $now;
        $rep->next_review_at = $now->addDays($interval);
        $rep->is_hard = $isHard;

        $rep->save();

        return $rep;
    }

    private function newEasinessFactor(float $ef, int $quality): float
    {
        $delta = 0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02);
        return max(self::MIN_EF, $ef + $delta);
    }

    private function assertQuality(int $q): void
    {
        if ($q < 0 || $q > 5) {
            throw new \InvalidArgumentException("Quality must be 0..5, got {$q}");
        }
    }
}
```

## 5. Выборка «что показать студенту»

```php
<?php

namespace App\Domain\Learning\Services;

use App\Domain\Content\Models\Word;
use App\Domain\Learning\Models\WordRepetition;

final class CardPicker
{
    public function __construct(private SpacedRepetitionEngine $engine) {}

    /**
     * @return array{ card: ?array, kind: 'due'|'new'|null }
     */
    public function pickNext(int $studentId, int $lessonId, int $batchSize = 15): array
    {
        // 1. due to review (в рамках урока или в рамках всех слов, которые студент уже видел)
        $due = WordRepetition::query()
            ->where('student_id', $studentId)
            ->whereHas('word', fn ($q) => $q->where('lesson_id', $lessonId))
            ->where('next_review_at', '<=', now())
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->with('word')
            ->first();

        if ($due) {
            return ['card' => $due->word->toArray(), 'kind' => 'due'];
        }

        // 2. новые слова — те, которых нет в word_repetitions у студента
        $new = Word::query()
            ->where('lesson_id', $lessonId)
            ->whereNotIn('id', function ($q) use ($studentId) {
                $q->select('word_id')->from('word_repetitions')->where('student_id', $studentId);
            })
            ->orderBy('id')
            ->first();

        if ($new) {
            // создаём начальное состояние
            $rep = WordRepetition::create([
                'student_id' => $studentId,
                'word_id' => $new->id,
                'repetitions' => 0,
                'interval_days' => 0,
                'easiness_factor' => SpacedRepetitionEngine::DEFAULT_EF,
                'next_review_at' => now(),
                'is_hard' => false,
            ]);

            return ['card' => $new->toArray(), 'kind' => 'new'];
        }

        return ['card' => null, 'kind' => null];
    }
}
```

## 6. Персональные напоминания (cron)

```php
<?php
// app/Console/Commands/NotifyDueRepetitions.php

namespace App\Console\Commands;

use App\Domain\Group\Models\Student;
use App\Domain\Learning\Jobs\SendReviewReminderJob;
use Illuminate\Console\Command;

class NotifyDueRepetitions extends Command
{
    protected $signature = 'repetitions:notify {--threshold=5 : min words due}';
    protected $description = 'Find students with due cards and queue notifications';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');

        Student::query()
            ->where('is_active', true)
            ->whereHas('group', fn ($q) => $q->where('status', 'active'))
            ->withCount(['wordRepetitions as due_count' => fn ($q) => $q->where('next_review_at', '<=', now())])
            ->having('due_count', '>=', $threshold)
            ->whereDoesntHave('notificationsLog', function ($q) {
                $q->where('type', 'review_reminder')
                  ->where('sent_at', '>=', now()->subHours(24));
            })
            ->chunkById(200, function ($students) {
                foreach ($students as $s) {
                    SendReviewReminderJob::dispatch($s->id, $s->due_count)->onQueue('default');
                }
            });

        return self::SUCCESS;
    }
}
```

Расписание: `$schedule->command('repetitions:notify')->dailyAt('18:00');`

## 7. Тестирование

### 7.1 Unit-тест на движок
```php
// tests/Unit/SpacedRepetitionEngineTest.php (скелет)

it('resets interval on quality < 3', function () {
    $rep = WordRepetition::factory()->create([
        'repetitions' => 5,
        'interval_days' => 30,
        'easiness_factor' => 2.40,
    ]);

    app(SpacedRepetitionEngine::class)->applyReview($rep, quality: 2);

    expect($rep->fresh())
        ->repetitions->toBe(0)
        ->interval_days->toBe(1)
        ->is_hard->toBeTrue();
});

it('uses interval=1 for first repetition', function () { /* ... */ });
it('uses interval=6 for second repetition', function () { /* ... */ });
it('multiplies by EF from third onwards', function () { /* ... */ });
it('never lowers EF below 1.3', function () { /* ... */ });
```

### 7.2 Feature-тест на полный цикл
«Студент трижды правильно отвечает на новую карточку → интервалы 1, 6, ~15 дней».

## 8. Аналитика на основе SM-2

- **Трудное слово для группы** — где у ≥ 40% студентов `last_quality < 3` (при min. 10 попыток).
- **Прогресс студента** — средний EF по всем его карточкам (растёт = прогресс).
- **Heatmap активности** — количество `training_reviews` на студента по дням недели.

## 9. Будущие улучшения (не MVP)

- Учёт времени ответа (`time_spent_ms`): если ответил «легко» за 10 секунд — подозрительно, сбавить EF прирост.
- FSRS (Free Spaced Repetition Scheduler) — более современная модель на основе градиентного бустинга. Требует больше данных.
- Leech-detection: если слово уже 5+ раз «провалено» — пометить как leech, показать учителю «студенту X сложно даётся Y, обрати внимание».
- Fuzzing интервалов: к next_review_at добавлять случайный ±10%, чтобы не возникало «стен» в один день.

## 10. Канонические источники
- Оригинальный пейпер SM-2: `https://super-memory.com/english/ol/sm2.htm`
- Открытая Anki реализация (обёртка SM-2 с модификациями): `https://docs.ankiweb.net/`
- FSRS для сравнения: `https://github.com/open-spaced-repetition/fsrs4anki`
