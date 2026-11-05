<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Pdo\Pgsql;

/**
 * Persistent PDO connections (DB_PERSISTENT=true in production, docs/DEVOPS_GUIDE.md §0 «pgbouncer и постоянные
 * соединения»): a php-fpm worker keeps the PostgreSQL session between requests, so whatever a request leaves behind in
 * the session would greet the next one. What is modelled here is the end of a request: Laravel drops the Connection
 * (DB::purge), the PDO object is freed, and PHP rolls back an open transaction of a persistent handle in that very
 * free (ext/pdo/pdo_dbh.c, pdo_dbh_free_storage: driver in_transaction + rollback) - the same handler runs at request
 * shutdown after a normal end, an unhandled exception, a fatal error and max_execution_time. The next DB::connection()
 * gets the same handle back from PHP's persistent list (PDO::connect: check_liveness, then reuse).
 *
 * The handle used here is private to this file: its own application_name is part of the persistent key, so no other test
 * can get it, and the forked children of the race tests never use it (they die by SIGKILL, never closing it either).
 *
 * PostgreSQL only. Behind pgbouncer in transaction mode (DB_VIA_PGBOUNCER=1 when the suite runs against the pooler) a
 * session is no longer one backend, so the assertions that identify the session by its backend pid are skipped there.
 */
const PERSISTENT_TEST_CONNECTION = 'pgsql_persistent_test';
const PERSISTENT_TEST_PROBE = 'pgsql_persistent_probe';
const PERSISTENT_TEST_APP_NAME = 'lexiflow-persistent-test';
const PERSISTENT_TEST_TABLE = 'lexiflow_persistent_probe';

function persistentTestViaPgbouncer(): bool
{
    return filter_var(env('DB_VIA_PGBOUNCER', false), FILTER_VALIDATE_BOOLEAN);
}

function persistentTestConnection(): Connection
{
    return DB::connection(PERSISTENT_TEST_CONNECTION);
}

/** A plain, non-persistent connection of its own: the observer, never the subject. */
function persistentTestProbe(): Connection
{
    return DB::connection(PERSISTENT_TEST_PROBE);
}

function persistentTestBackendPid(Connection $connection): int
{
    return (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
}

function persistentTestIdleInTransactionSessions(): int
{
    return (int) persistentTestProbe()->selectOne(
        "select count(*) as n from pg_stat_activity where application_name = ? and state = 'idle in transaction'",
        [PERSISTENT_TEST_APP_NAME],
    )->n;
}

function persistentTestProbeTableExists(): bool
{
    return persistentTestProbe()->selectOne('select to_regclass(?) as oid', [PERSISTENT_TEST_TABLE])->oid !== null;
}

/**
 * pg_terminate_backend() only signals: the backend closes its socket a moment later. Wait for it, as a restart in
 * production is long over when the next request comes in - the point under test is the dead socket, not the race.
 */
function persistentTestTerminateBackend(int $pid): void
{
    $probe = persistentTestProbe();
    $probe->select('select pg_terminate_backend(?)', [$pid]);

    for ($i = 0; $i < 200; $i++) {
        if ((int) $probe->selectOne('select count(*) as n from pg_stat_activity where pid = ?', [$pid])->n === 0) {
            return;
        }
        usleep(10_000);
    }

    throw new RuntimeException("backend {$pid} is still alive two seconds after pg_terminate_backend()");
}

beforeEach(function (): void {
    if (! extension_loaded('pdo_pgsql') || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Persistent connections are a PostgreSQL matter (the suite runs on sqlite here).');
    }

    /** @var array<string, mixed> $base */
    $base = config('database.connections.pgsql');
    /** @var array<int, mixed> $options */
    $options = $base['options'] ?? [];

    config()->set('database.connections.'.PERSISTENT_TEST_CONNECTION, array_replace($base, [
        'application_name' => PERSISTENT_TEST_APP_NAME,
        'options' => [PDO::ATTR_PERSISTENT => true] + $options,
    ]));
    config()->set('database.connections.'.PERSISTENT_TEST_PROBE, array_replace($base, [
        'options' => [PDO::ATTR_PERSISTENT => false] + $options,
    ]));
});

afterEach(function (): void {
    if (! extension_loaded('pdo_pgsql') || DB::connection()->getDriverName() !== 'pgsql') {
        return;
    }

    try {
        persistentTestProbe()->statement('drop table if exists '.PERSISTENT_TEST_TABLE);
    } finally {
        // The persistent handle itself stays in PHP's list for the rest of the process, idle: nothing else uses its key,
        // and the children the race tests fork never touch it and die by SIGKILL (no shutdown, no Terminate message).
        DB::purge(PERSISTENT_TEST_CONNECTION);
        DB::purge(PERSISTENT_TEST_PROBE);
    }
});

it('gives the next request the same session, with the transaction the previous one abandoned rolled back', function (): void {
    $first = persistentTestConnection();
    expect($first->getPdo()->getAttribute(PDO::ATTR_PERSISTENT))->toBeTrue();
    $pid = persistentTestBackendPid($first);

    // A request that began a transaction and never finished it (an unhandled exception past Laravel's own rollback,
    // a fatal error): DDL is transactional in PostgreSQL, so the table is the marker of the transaction's fate.
    $first->beginTransaction();
    $first->statement('create table '.PERSISTENT_TEST_TABLE.' (id int)');
    expect($first->transactionLevel())->toBe(1)
        ->and(persistentTestIdleInTransactionSessions())->toBe(1);

    // End of the request: the Connection and its PDO object go away; the session itself stays open.
    DB::purge(PERSISTENT_TEST_CONNECTION);

    expect(persistentTestIdleInTransactionSessions())->toBe(0)
        ->and(persistentTestProbeTableExists())->toBeFalse();

    // Next request.
    $second = persistentTestConnection();
    expect($second->transactionLevel())->toBe(0)
        ->and($second->getPdo()->inTransaction())->toBeFalse()
        ->and($second->selectOne('show search_path')->search_path)->toContain('public')
        ->and($second->selectOne('select 1 as one')->one)->toBe(1);

    if (! persistentTestViaPgbouncer()) {
        expect(persistentTestBackendPid($second))->toBe($pid);
    }
});

it('leaves a clean handle for the next request after an exception inside DB::transaction()', function (): void {
    $first = persistentTestConnection();

    expect(fn () => $first->transaction(function (Connection $connection): void {
        $connection->statement('create table '.PERSISTENT_TEST_TABLE.' (id int)');
        throw new RuntimeException('the request blew up inside the transaction');
    }))->toThrow(RuntimeException::class);

    expect($first->transactionLevel())->toBe(0)
        ->and($first->getPdo()->inTransaction())->toBeFalse()
        ->and(persistentTestProbeTableExists())->toBeFalse();

    DB::purge(PERSISTENT_TEST_CONNECTION);

    $second = persistentTestConnection();
    expect($second->transactionLevel())->toBe(0)
        ->and($second->getPdo()->inTransaction())->toBeFalse()
        ->and(persistentTestIdleInTransactionSessions())->toBe(0)
        ->and($second->transaction(fn (Connection $c): int => (int) $c->selectOne('select 2 as two')->two))->toBe(2);
});

it('re-establishes a handle whose backend died while the worker was idle, on the next query', function (): void {
    $connection = persistentTestConnection();
    $pid = persistentTestBackendPid($connection);

    // PostgreSQL restarted (or pgbouncer dropped the server) while this worker served nothing.
    persistentTestTerminateBackend($pid);

    // Outside a transaction Laravel reconnects on a lost connection and runs the query again; PDO::connect() finds the
    // persistent handle dead (check_liveness: PQconsumeInput) and resets it in place.
    expect(persistentTestBackendPid($connection))->not->toBe($pid)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse();
});

it('fails the request whose transaction lost its backend and hands the next one a clean handle', function (): void {
    $first = persistentTestConnection();
    $pid = 0;
    $thrown = null;

    try {
        $first->transaction(function (Connection $connection) use (&$pid): void {
            $pid = persistentTestBackendPid($connection);
            persistentTestTerminateBackend($pid);
            $connection->selectOne('select 1 as one');
        });
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // Inside a transaction a lost connection is never retried: the request fails, Laravel's level is reset.
    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and($first->transactionLevel())->toBe(0);

    DB::purge(PERSISTENT_TEST_CONNECTION);

    $second = persistentTestConnection();
    expect($second->transactionLevel())->toBe(0)
        ->and($second->getPdo()->inTransaction())->toBeFalse()
        ->and(persistentTestBackendPid($second))->not->toBe($pid)
        ->and($second->transaction(fn (Connection $c): int => (int) $c->selectOne('select 3 as three')->three))->toBe(3);
});

it('leaves no server-side prepared statement behind: queries are unnamed statements by default', function (): void {
    $connection = persistentTestConnection();
    expect($connection->getPdo()->getAttribute(Pgsql::ATTR_DISABLE_PREPARES))->toBeTrue();

    // One transaction = one backend, pooled or not, so pg_prepared_statements is the session the queries ran in.
    $connection->transaction(function (Connection $c): void {
        $row = $c->selectOne('select ?::int as a, ?::text as b', [7, 'seven']);
        expect($row->a)->toBe(7)->and($row->b)->toBe('seven');
        $c->select('select ?::int as a', [8]);

        expect((int) $c->selectOne('select count(*) as n from pg_prepared_statements')->n)->toBe(0);
    });
});

it('would otherwise prepare, execute once and close a named statement per query', function (): void {
    if (persistentTestViaPgbouncer()) {
        $this->markTestSkipped('pgbouncer renames and caches the statements on its server connections.');
    }

    /** @var array<string, mixed> $base */
    $base = config('database.connections.pgsql');
    config()->set('database.connections.pgsql_named_statements', array_replace($base, [
        'options' => [Pgsql::ATTR_DISABLE_PREPARES => false, PDO::ATTR_PERSISTENT => false],
    ]));

    try {
        $connection = DB::connection('pgsql_named_statements');
        $pdo = $connection->getPdo();

        $statement = $pdo->prepare('select ?::int as a');
        $statement->execute([1]);
        // The catalog query is a named statement of its own in this mode: it always sees itself.
        $names = $pdo->query('select name from pg_prepared_statements order by name')->fetchAll(PDO::FETCH_COLUMN);
        expect($names)->toHaveCount(2)
            ->and($names[0])->toStartWith('pdo_stmt_')
            ->and($names[1])->toStartWith('pdo_stmt_');

        // The statement object goes away: pdo_pgsql closes the named statement (protocol Close with libpq >= 17,
        // SQL DEALLOCATE before) - a round trip Laravel pays for every query in this mode, and the one pgbouncer
        // could not route in the SQL form.
        unset($statement);
        expect($pdo->query('select count(*) from pg_prepared_statements')->fetchColumn())->toBe(1);
    } finally {
        DB::purge('pgsql_named_statements');
    }
});
