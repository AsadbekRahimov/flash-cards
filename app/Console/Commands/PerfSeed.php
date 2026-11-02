<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\DTO\PerfSeedOptions;
use App\Domain\Operations\Services\PerfSeeder;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * `perf:seed` (docs/20 F0, docs/19 C0): volume data for measuring the admin panels and the hot TWA endpoints - by default
 * 200 000 learners, 100 000 words and everything that hangs on them (repetitions, payments, exams, centers, events).
 * Local and testing only, on an empty database (`migrate:fresh` first). The generation lives in {@see PerfSeeder}.
 */
final class PerfSeed extends Command
{
    /** @var string */
    protected $signature = 'perf:seed
        {--learners=200000 : Learners}
        {--words=100000 : Words, spread over the lessons of the courses (about 25 per lesson)}
        {--courses=12 : Published courses}
        {--repetitions=24 : word_repetitions per learner (the first 20 learners get 300)}
        {--reviews= : training_reviews (default: 3 per learner)}
        {--payers= : Learners with a subscription, order, transaction and payment (default: 6% of learners)}
        {--exams= : Closed exams: a sixth are personal mock exams (default: one per 20 learners)}
        {--organizations= : Centers with staff, members, groups and assignments (default: one per 2000 learners)}
        {--members=150 : Members per center}
        {--groups= : Standalone Telegram groups (default: one per 200 learners)}
        {--events= : product_events (default: 3 per learner)}
        {--seed=1 : Seed of the generator: the same options and seed give the same rows}';

    /** @var string */
    protected $description = 'Fill an empty database with volume data for measuring (local and testing only).';

    public function handle(PerfSeeder $seeder): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->components->error('perf:seed runs only in the local and testing environments (APP_ENV is "'.$this->laravel->environment().'").');

            return self::FAILURE;
        }

        try {
            $options = PerfSeedOptions::make(
                learners: $this->intOption('learners'),
                words: $this->intOption('words'),
                courses: $this->intOption('courses'),
                repetitionsPerLearner: $this->intOption('repetitions'),
                reviews: $this->optionalInt('reviews'),
                payers: $this->optionalInt('payers'),
                exams: $this->optionalInt('exams'),
                organizations: $this->optionalInt('organizations'),
                membersPerOrganization: $this->intOption('members'),
                groups: $this->optionalInt('groups'),
                events: $this->optionalInt('events'),
                seed: $this->intOption('seed'),
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $counts = $seeder->run($options, fn (string $line) => $this->line($line));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Table', 'Rows'], array_map(
            fn (string $table, int $rows): array => [$table, number_format($rows)],
            array_keys($counts),
            $counts,
        ));
        $this->components->info(sprintf('Done in %.1fs, %s rows. Platform admin: %s (2FA confirmed; the owner of the first center: %s).', microtime(true) - $started, number_format(array_sum($counts)), PerfSeeder::ADMIN_EMAIL, sprintf(PerfSeeder::OWNER_EMAIL, 1)));

        return self::SUCCESS;
    }

    private function intOption(string $name): int
    {
        return (int) $this->option($name);
    }

    private function optionalInt(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
