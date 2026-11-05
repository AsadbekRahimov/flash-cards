<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\Services\LoadTestTokenIssuer;
use App\Support\LocalPath;
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
            $this->components->error('--output must be "-" or a path inside storage/, but not storage/app/public (live tokens must not land anywhere else, least of all where the web server serves files).');

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

        // POSIX: 0600 from creation, even before the rename. On Windows the file inherits the directory ACL.
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $umask = umask(0o077);
        try {
            $written = file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR));
        } finally {
            umask($umask);
        }
        if ($written === false || (PHP_OS_FAMILY !== 'Windows' && ! chmod($tmp, 0o600)) || ! rename($tmp, $path)) {
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
        $output = str_replace('\\', '/', $output);
        if ($output === '' || str_contains($output, "\0") || in_array('..', explode('/', $output), true)) {
            return null;
        }

        // Win32 trims trailing dots/spaces and treats colons after the drive prefix as alternate streams.
        if (PHP_OS_FAMILY === 'Windows' && preg_match('~[^/][. ](?:/|$)|:~', preg_replace('~^[a-z]:/~i', '/', $output) ?? $output) === 1) {
            return null;
        }

        $path = str_replace('\\', '/', LocalPath::fromProject($output));
        $storage = $this->comparisonPath(realpath(storage_path()) ?: storage_path());

        $dir = dirname($path);
        while (! file_exists($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        $real = realpath($dir);
        if ($real === false) {
            return null;
        }

        $target = $this->comparisonPath($real.substr($path, strlen($dir))).'/';
        $public = $this->comparisonPath(realpath(storage_path('app/public')) ?: storage_path('app/public')).'/';
        if (! str_starts_with($target, $storage.'/') || str_starts_with($target, $public)) {
            return null;
        }

        return $path;
    }

    private function comparisonPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        $path = preg_replace('~/(?:\./)+~', '/', $path) ?? $path;

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;
    }
}
