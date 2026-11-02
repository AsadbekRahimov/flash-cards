<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use Closure;

/**
 * Memory of the `/admin` access checks for ONE request or ONE queued job (docs/20 §4, A11 for the teacher role).
 *
 * {@see PanelDataScope} keeps here the ids of the groups a teacher is assigned to (`teacher_groups`). The row policies
 * of the panel (`LearnerPolicy::view`, `ExamSessionPolicy::view`) are asked for every row of a list — the link, the
 * view button and each row action — and before this memory each of them asked the database (6 statements per row
 * of the learners and the exams of a teacher). Values are keyed by user id (the keys are built by `PanelDataScope`),
 * so a request serving one teacher never answers for another.
 *
 * Lifetime — a `scoped` binding of the container (AppServiceProvider), the same contract as the `OrgRequestMemo` of
 * the cabinet: PHP-FPM serves one request per process; the queue worker forgets scoped instances before every job
 * (`QueueServiceProvider::registerWorker`, Laravel 13); Octane forgets them between requests; in a long-lived process
 * without Octane (the test suite) the `RequestHandled` listener of AppServiceProvider flushes it after every request.
 *
 * `teacher_groups` is written only by an administrator (TelegramGroupResource, the staff service of a center) — never
 * by the teacher whose request holds the memory — so nothing inside a request changes what it remembers. A test that
 * rewires a teacher's groups between two checks in the same process calls {@see flush()}. Never inject it into a
 * singleton: a singleton would keep the instance of the request it was created in.
 */
final class PanelRequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * The remembered value of $key, loading it with $load the first time.
     *
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    public function remember(string $key, Closure $load): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $load();
        }

        /** @var T $value */
        $value = $this->values[$key];

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** Forgets everything: the next read of any value goes to the database. */
    public function flush(): void
    {
        $this->values = [];
    }
}
