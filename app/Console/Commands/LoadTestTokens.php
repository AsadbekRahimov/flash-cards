<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\Services\LoadTestTokenIssuer;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `load:tokens`: N test learners and their JWTs in a JSON file for the k6 scenarios (tests/load/k6). Staging and local only;
 * the logic lives in {@see LoadTestTokenIssuer}.
 */
final class LoadTestTokens extends Command
{
    /** @var string */
    protected $signature = 'load:tokens
        {--count=1250 : Test learners}
        {--ttl=7200 : Token lifetime in seconds}
        {--course= : Slug of the course the learners study (default: the first published one)}
        {--output=storage/app/load-tokens.json : File for k6, relative to the project root; "-" prints the JSON to stdout}';

    /** @var string */
    protected $description = 'Create test learners and write their JWTs to a JSON file for k6 (never in production).';

    public function handle(LoadTestTokenIssuer $issuer): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('load:tokens does not run in production.');

            return self::FAILURE;
        }

        $course = (string) $this->option('course');

        try {
            $data = $issuer->issue((int) $this->option('count'), (int) $this->option('ttl'), $course === '' ? null : $course);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $output = (string) $this->option('output');
        if ($output === '-') {
            $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $path = str_starts_with($output, '/') ? $output : base_path($output);

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0o700, true) && ! is_dir(dirname($path))) {
            $this->components->error('Cannot create the directory of '.$path.'.');

            return self::FAILURE;
        }

        if (file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)) === false) {
            $this->components->error('Cannot write '.$path.'.');

            return self::FAILURE;
        }
        chmod($path, 0o600);

        $this->components->info(sprintf('%d tokens written to %s (valid until %s, course: %s). The file holds live tokens: delete it after the run.', count($data['learners']), $path, date('Y-m-d H:i:s', $data['expires_at']), $data['course'] ?? 'none'));

        return self::SUCCESS;
    }
}
