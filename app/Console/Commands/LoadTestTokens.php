<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\Services\LoadTestTokenIssuer;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `load:tokens`: N test learners and their JWTs in a JSON file for the k6 scenarios (tests/load/k6). Local, testing and staging only;
 * the logic lives in {@see LoadTestTokenIssuer}.
 */
final class LoadTestTokens extends Command
{
    /** @var string */
    protected $signature = 'load:tokens
        {--count=1250 : Test learners}
        {--ttl=7200 : Token lifetime in seconds}
        {--course= : Slug of the course the learners study (default: the first published one)}
        {--output=storage/app/load-tokens.json : File for k6 inside storage/ (relative to the project root); "-" prints the JSON to stdout}';

    /** @var string */
    protected $description = 'Create test learners and write their JWTs to a JSON file for k6 (local, testing, staging only).';

    public function handle(LoadTestTokenIssuer $issuer): int
    {
        if (! $this->laravel->environment(LoadTestTokenIssuer::ALLOWED_ENVIRONMENTS)) {
            $this->components->error('load:tokens runs only in the '.implode(', ', LoadTestTokenIssuer::ALLOWED_ENVIRONMENTS).' environments (APP_ENV is "'.$this->laravel->environment().'").');

            return self::FAILURE;
        }

        $output = (string) $this->option('output');
        $path = $output === '-' ? null : $this->resolvePath($output);
        if ($output !== '-' && $path === null) {
            $this->components->error('--output must be "-" or a path inside storage/ (live tokens must not land anywhere else, least of all public/).');

            return self::FAILURE;
        }

        $course = (string) $this->option('course');

        try {
            $data = $issuer->issue((int) $this->option('count'), (int) $this->option('ttl'), $course === '' ? null : $course);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($path === null) {
            $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0o700, true) && ! is_dir(dirname($path))) {
            $this->components->error('Cannot create the directory of '.$path.'.');

            return self::FAILURE;
        }

        // The file is born 0600 (never world-readable, even for a moment): written under a restrictive umask to a temp
        // file next to the target, then renamed over it.
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $umask = umask(0o077);
        try {
            $written = file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR));
        } finally {
            umask($umask);
        }
        if ($written === false || ! chmod($tmp, 0o600) || ! rename($tmp, $path)) {
            @unlink($tmp);
            $this->components->error('Cannot write '.$path.'.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('%d tokens written to %s (valid until %s, course: %s). The file holds live tokens: delete it after the run.', count($data['learners']), $path, date('Y-m-d H:i:s', $data['expires_at']), $data['course'] ?? 'none'));

        return self::SUCCESS;
    }

    /** Absolute path inside storage/ (no `..`, symlinks of existing parents resolved), or null. */
    private function resolvePath(string $output): ?string
    {
        if ($output === '' || str_contains($output, "\0") || in_array('..', explode('/', str_replace('\\', '/', $output)), true)) {
            return null;
        }

        $path = str_starts_with($output, '/') ? $output : base_path($output);
        $storage = realpath(storage_path()) ?: storage_path();

        $dir = dirname($path);
        while (! file_exists($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        $real = realpath($dir);

        if ($real === false || ($real !== $storage && ! str_starts_with($real, $storage.DIRECTORY_SEPARATOR))) {
            return null;
        }

        return $path;
    }
}
