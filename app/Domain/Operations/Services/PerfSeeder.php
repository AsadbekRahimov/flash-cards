<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\DTO\PerfSeedOptions;
use App\Domain\Operations\Support\PerfBatchWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Volume data for measuring (`php artisan perf:seed`, docs/20 F0, docs/19 C0): learners, a catalog with translations,
 * repetitions and training reviews, payments, exams, centers with members, groups and assignments, product events,
 * leagues and friendships. Only for local and testing - the demo seeders have the same rule.
 *
 * Rows go in with multi-row INSERTs (never one model at a time), with explicit ids taken after the current maximum, so
 * the data can sit next to the demo data. The run is one transaction: it either leaves the whole data set or nothing.
 * A seed makes it reproducible: the same options and seed give the same rows (ids aside, when the tables are not empty).
 */
final class PerfSeeder
{
    /** The first seeded course; its presence means "already seeded". */
    public const COURSE_SLUG = 'perf-course-1';

    /** Platform administrator of the data set: the measurements sign in as this user. */
    public const ADMIN_EMAIL = 'perf-admin@example.test';

    /** Learner number N (from 1) has this telegram_user_id + N. */
    public const TELEGRAM_USER_BASE = 7_000_000_000;

    public const ORGANIZATION_SLUG = 'perf-center-%d';

    public const OWNER_EMAIL = 'perf-owner-%d@example.test';

    private const CHAT_ID_BASE = -1_007_000_000_000;

    private const DAY = 86_400;

    private const GROUPS_PER_ORGANIZATION = 4;

    private const ASSIGNMENTS_PER_GROUP = 3;

    private const GROUP_SIZE = 25;

    /** Tables the run writes, parents first; a test (or a clean-up) empties them in reverse order. */
    public const TABLES = [
        'users', 'model_has_roles', 'organizations', 'organization_users', 'courses', 'stages', 'lessons', 'words',
        'word_translations', 'learners', 'telegram_groups', 'organization_invites', 'organization_members',
        'group_members', 'assignments', 'assignment_results', 'word_repetitions', 'training_sessions',
        'training_reviews', 'learner_daily_stats', 'subscriptions', 'payment_orders', 'provider_transactions',
        'payments', 'exam_sessions', 'exam_answers', 'exam_results', 'product_events', 'league_weeks',
        'league_groups', 'league_members', 'friendships',
    ];

    /** Tables whose rows other tables point at: their id sequences are moved past the seeded ids at the end. */
    private const SEQUENCES = [
        'users', 'organizations', 'courses', 'stages', 'lessons', 'words', 'learners', 'telegram_groups',
        'assignments', 'training_sessions', 'subscriptions', 'payment_orders', 'provider_transactions', 'payments',
        'exam_sessions', 'league_weeks', 'league_groups',
    ];

    private const FIRST_NAMES = ['Aziz', 'Dilnoza', 'Jasur', 'Madina', 'Sardor', 'Nodira', 'Bobur', 'Zilola', 'Otabek', 'Malika', 'Rustam', 'Gulnora', 'Timur', 'Sevara', 'Anvar', 'Kamola', 'Shaxzod', 'Feruza', 'Eldor', 'Laylo', 'Anna', 'Dmitry', 'Elena', 'Ivan', 'Olga', 'Sergey', 'Natalia', 'Pavel', 'Irina', 'Maxim', 'Alina', 'Kirill', 'Yulia', 'Artem', 'Daria', 'Nikita', 'Sofia', 'Andrey', 'Polina', 'Roman'];

    private const LAST_NAMES = ['Karimov', 'Rahimova', 'Yusupov', 'Abdullayeva', 'Tursunov', 'Ismoilova', 'Nazarov', 'Saidova', 'Ergashev', 'Mirzayeva', 'Ivanov', 'Petrova', 'Sidorov', 'Smirnova', 'Kuznetsov', 'Popova', 'Volkov', 'Sokolova', 'Lebedev', 'Kozlova'];

    private const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1'];

    private const GOALS = ['general', 'ielts', 'work', 'travel', 'school'];

    /** Pseudo-words of exact two-letter syllables: n -> a string that is different for every n. */
    private const LATIN = ['ba', 'be', 'bi', 'bo', 'bu', 'da', 'de', 'di', 'do', 'du', 'fa', 'fe', 'fi', 'fo', 'fu', 'ga', 'ge', 'gi', 'go', 'gu', 'ka', 'ke', 'ki', 'ko', 'ku', 'la', 'le', 'li', 'lo', 'lu', 'ma', 'me', 'mi', 'mo', 'mu', 'na', 'ne', 'ni', 'no', 'nu', 'pa', 'pe', 'pi', 'po', 'pu', 'ra', 're', 'ri', 'ro', 'ru', 'sa', 'se', 'si', 'so', 'su', 'ta', 'te', 'ti', 'to', 'tu', 'va', 've', 'vi', 'vo', 'vu', 'za', 'ze', 'zi', 'zo', 'zu'];

    private const CYRILLIC = ['ба', 'бе', 'би', 'бо', 'бу', 'да', 'де', 'ди', 'до', 'ду', 'фа', 'фе', 'фи', 'фо', 'фу', 'га', 'ге', 'ги', 'го', 'гу', 'ка', 'ке', 'ки', 'ко', 'ку', 'ла', 'ле', 'ли', 'ло', 'лу', 'ма', 'ме', 'ми', 'мо', 'му', 'на', 'не', 'ни', 'но', 'ну', 'па', 'пе', 'пи', 'по', 'пу', 'ра', 'ре', 'ри', 'ро', 'ру', 'са', 'се', 'си', 'со', 'су', 'та', 'те', 'ти', 'то', 'ту', 'ва', 'ве', 'ви', 'во', 'ву', 'за', 'зе', 'зи', 'зо', 'зу'];

    private const PARTS_OF_SPEECH = ['noun', 'verb', 'adjective', 'adverb', 'preposition'];

    /** Product events with weights: the funnel and the activity widgets read them by name. */
    private const EVENTS = [
        'twa.authenticated' => 8, 'onboarding.started' => 2, 'onboarding.step_completed' => 4,
        'onboarding.completed' => 2, 'course.selected' => 2, 'training.session_opened' => 6,
        'training.session_finished' => 5, 'exam.started' => 1, 'exam.closed' => 1, 'reminder.sent' => 6,
        'settings.changed' => 1, 'group.member_joined' => 1,
    ];

    private Randomizer $rng;

    private ?Closure $log = null;

    /** Seconds since the epoch the run treats as "now". */
    private int $now = 0;

    /** @var array<string, int> rows written per table */
    private array $counts = [];

    private int $learnerBase = 0;

    private int $userBase = 0;

    private int $organizationBase = 0;

    private int $adminId = 0;

    /** @var list<int> */
    private array $courseIds = [];

    /** @var list<int> first word id of each course (words of a course have consecutive ids) */
    private array $courseFirstWord = [];

    /** @var list<int> */
    private array $courseWordCount = [];

    /** @var list<list<int>> lesson ids of each course */
    private array $courseLessons = [];

    /** @var array<int, int> lesson id => first word id */
    private array $lessonFirstWord = [];

    /** @var array<int, int> lesson id => number of words */
    private array $lessonWordCount = [];

    /** @var list<array{id: int, course: int}> every Telegram group of the data set (centers' and standalone) */
    private array $groups = [];

    /** @var array<string, int> per table: the next free id before the run */
    private array $nextIds = [];

    /**
     * @param  (Closure(string): void)|null  $log  progress lines
     * @return array<string, int> rows written per table
     */
    public function run(PerfSeedOptions $o, ?Closure $log = null): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('perf:seed fills the database with generated volumes and runs only in the local and testing environments (APP_ENV is "'.app()->environment().'").');
        }

        if (DB::table('courses')->where('slug', self::COURSE_SLUG)->exists()) {
            throw new RuntimeException('This database already holds a perf:seed data set. Start from an empty one: php artisan migrate:fresh.');
        }

        DB::connection()->disableQueryLog();

        $this->log = $log;
        $this->rng = new Randomizer(new Mt19937($o->seed));
        $this->now = now()->getTimestamp();
        $this->counts = [];
        $this->groups = [];
        $this->nextIds = [];

        DB::transaction(function () use ($o): void {
            $this->section('platform administrator', fn () => $this->seedAdmin());
            $this->section('center staff, centers', fn () => $this->seedOrganizations($o));
            $this->section('catalog: courses, stages, lessons, words, translations', fn () => $this->seedCatalog($o));
            $this->section('learners', fn () => $this->seedLearners($o));
            $this->section('telegram groups, members, invites, assignments', fn () => $this->seedGroups($o));
            $this->section('word repetitions', fn () => $this->seedRepetitions($o));
            $this->section('training sessions and reviews', fn () => $this->seedTraining($o));
            $this->section('daily stats', fn () => $this->seedDailyStats($o));
            $this->section('subscriptions, orders, transactions, payments', fn () => $this->seedBilling($o));
            $this->section('exams: sessions, answers, results', fn () => $this->seedExams($o));
            $this->section('product events', fn () => $this->seedEvents($o));
            $this->section('league and friendships', fn () => $this->seedSocial($o));
            $this->section('counters and id sequences', fn () => $this->finish());
        });

        // Statistics for the planner, after the commit: a measurement on a table nobody analysed shows the plan of an
        // empty one (autovacuum would get there some minutes later).
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->section('analyze', function (): void {
                DB::statement('ANALYZE');
            });
        }

        return $this->counts;
    }

    /** @param  Closure(): void  $work */
    private function section(string $name, Closure $work): void
    {
        $started = microtime(true);
        $before = array_sum($this->counts);

        $work();

        if ($this->log !== null) {
            ($this->log)(sprintf('%-62s %9s rows  %6.1fs', $name, number_format(array_sum($this->counts) - $before), microtime(true) - $started));
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Staff and centers
    // ------------------------------------------------------------------------------------------------------------

    private function seedAdmin(): void
    {
        app(RolePermissionSeeder::class)->run();

        $verified = CarbonImmutable::createFromTimestampUTC($this->now);

        $admin = User::query()->firstOrNew(['email' => self::ADMIN_EMAIL]);
        $admin->forceFill([
            'name' => 'Perf Admin',
            'password' => 'password',
            'is_active' => true,
            'email_verified_at' => $verified,
            'two_factor_secret' => 'stub',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => $verified,
        ])->save();
        $admin->syncRoles(['admin']);

        $this->adminId = (int) $admin->getKey();
        $this->counts['users'] = ($this->counts['users'] ?? 0) + 1;
    }

    private function seedOrganizations(PerfSeedOptions $o): void
    {
        $organizations = $this->organizationCount($o);

        if ($organizations === 0) {
            return;
        }

        $this->userBase = $this->nextId('users');
        $this->organizationBase = $this->nextId('organizations');

        // Owner and two teachers per center. 2FA is confirmed for everyone, so the /org panel opens for any of them.
        $hash = Hash::make('password');
        $secret = Crypt::encryptString('stub');
        $codes = Crypt::encryptString('[]');
        $at = $this->at(0);

        $users = new PerfBatchWriter('users');
        for ($i = 0; $i < $organizations; $i++) {
            foreach ([sprintf(self::OWNER_EMAIL, $i + 1), 'perf-teacher-'.($i + 1).'-1@example.test', 'perf-teacher-'.($i + 1).'-2@example.test'] as $slot => $email) {
                $users->add([
                    'id' => $this->userBase + $i * 3 + $slot,
                    'name' => $slot === 0 ? 'Owner of center '.($i + 1) : 'Teacher '.$slot.' of center '.($i + 1),
                    'email' => $email,
                    'email_verified_at' => $at,
                    'password' => $hash,
                    'is_active' => true,
                    'two_factor_secret' => $secret,
                    'two_factor_recovery_codes' => $codes,
                    'two_factor_confirmed_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($users);

        $rows = new PerfBatchWriter('organizations');
        for ($i = 0; $i < $organizations; $i++) {
            $rows->add([
                'id' => $this->organizationBase + $i,
                'name' => 'Perf Learning Center '.($i + 1),
                'slug' => sprintf(self::ORGANIZATION_SLUG, $i + 1),
                'type' => 'center',
                'status' => 'active',
                'timezone' => 'Asia/Tashkent',
                'locale' => 'ru',
                'settings' => '{}',
                'created_at' => $this->ago(($organizations - $i) * self::DAY),
                'updated_at' => $at,
            ]);
        }
        $this->done($rows);

        $staff = new PerfBatchWriter('organization_users');
        for ($i = 0; $i < $organizations; $i++) {
            foreach (['owner', 'teacher', 'teacher'] as $slot => $role) {
                $staff->add([
                    'organization_id' => $this->organizationBase + $i,
                    'user_id' => $this->userBase + $i * 3 + $slot,
                    'role' => $role,
                    'invited_by_user_id' => null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($staff);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------------------------------------------------

    private function seedCatalog(PerfSeedOptions $o): void
    {
        // About 25 words per lesson; every lesson has at least one word, every course at least one lesson.
        $lessonsTotal = max($o->courses, min($o->words, intdiv($o->words + 24, 25)));
        $lessonsPerCourse = intdiv($lessonsTotal, $o->courses);
        $lessonsExtra = $lessonsTotal % $o->courses;
        $wordsPerLesson = intdiv($o->words, $lessonsTotal);
        $wordsExtra = $o->words % $lessonsTotal;

        $courseBase = $this->nextId('courses');
        $stageBase = $this->nextId('stages');
        $lessonBase = $this->nextId('lessons');
        $wordBase = $this->nextId('words');
        $at = $this->at(0);

        $this->courseIds = [];
        $this->courseLessons = [];
        $this->courseFirstWord = [];
        $this->courseWordCount = [];
        $this->lessonFirstWord = [];
        $this->lessonWordCount = [];

        $courses = new PerfBatchWriter('courses');
        for ($c = 0; $c < $o->courses; $c++) {
            $this->courseIds[] = $courseBase + $c;
            $courses->add([
                'id' => $courseBase + $c,
                'slug' => 'perf-course-'.($c + 1),
                'owner_type' => 'platform',
                'owner_id' => null,
                'source' => 'platform',
                'title' => json_encode(['ru' => 'Курс '.($c + 1), 'uz' => 'Kurs '.($c + 1), 'en' => 'Course '.($c + 1)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'description' => '{}',
                'target_language' => 'en',
                'level' => self::LEVELS[$c % count(self::LEVELS)],
                'exam_tag' => $c % 4 === 0 ? 'ielts' : null,
                'visibility' => 'public',
                'status' => 'published',
                'published_at' => $at,
                'position' => $c,
                'is_featured' => $c === 0,
                'words_count' => 0,
                'meta' => '{}',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
        $this->done($courses);

        // Stages: up to 8 per course; lessons are dealt over them evenly.
        $stages = new PerfBatchWriter('stages');
        $stageIds = [];
        $stageId = $stageBase;
        for ($c = 0; $c < $o->courses; $c++) {
            $lessons = $lessonsPerCourse + ($c < $lessonsExtra ? 1 : 0);
            $count = min(8, $lessons);
            $stageIds[$c] = [$stageId, $count, $lessons];

            for ($s = 0; $s < $count; $s++) {
                $stages->add([
                    'id' => $stageId + $s,
                    'course_id' => $courseBase + $c,
                    'number' => $s + 1,
                    'title' => 'Stage '.($s + 1),
                    'description' => null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
            $stageId += $count;
        }
        $this->done($stages);

        $lessonRows = new PerfBatchWriter('lessons');
        $lessonId = $lessonBase;
        $global = 0;
        for ($c = 0; $c < $o->courses; $c++) {
            [$firstStage, $stageCount, $lessons] = $stageIds[$c];
            $perStage = array_fill(0, $stageCount, 0);
            $this->courseLessons[$c] = [];
            $this->courseFirstWord[$c] = $wordBase + $global * $wordsPerLesson + min($global, $wordsExtra);
            $words = 0;

            for ($k = 0; $k < $lessons; $k++) {
                $stage = intdiv($k * $stageCount, $lessons);
                $perStage[$stage]++;
                $wordCount = $wordsPerLesson + ($global < $wordsExtra ? 1 : 0);

                $lessonRows->add([
                    'id' => $lessonId,
                    'stage_id' => $firstStage + $stage,
                    'number' => $perStage[$stage],
                    'title' => 'Lesson '.($k + 1),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);

                $this->lessonFirstWord[$lessonId] = $wordBase + $global * $wordsPerLesson + min($global, $wordsExtra);
                $this->lessonWordCount[$lessonId] = $wordCount;
                $this->courseLessons[$c][] = $lessonId;
                $words += $wordCount;
                $lessonId++;
                $global++;
            }

            $this->courseWordCount[$c] = $words;
        }
        $this->done($lessonRows);

        $activeByCourse = array_fill(0, $o->courses, 0);
        $wordRows = new PerfBatchWriter('words');
        foreach ($this->courseLessons as $c => $lessonIds) {
            foreach ($lessonIds as $lessonId) {
                $first = $this->lessonFirstWord[$lessonId];

                for ($i = 0; $i < $this->lessonWordCount[$lessonId]; $i++) {
                    $id = $first + $i;
                    $n = $id - $wordBase;
                    $active = $n % 33 !== 0;
                    $activeByCourse[$c] += $active ? 1 : 0;

                    $wordRows->add([
                        'id' => $id,
                        'lesson_id' => $lessonId,
                        'word' => $this->pseudoWord(self::LATIN, $n),
                        'translation' => $this->pseudoWord(self::CYRILLIC, $n),
                        'example' => $n % 3 === 0 ? 'This is an example sentence for the word number '.$n.'.' : null,
                        'part_of_speech' => self::PARTS_OF_SPEECH[$n % count(self::PARTS_OF_SPEECH)],
                        'transcription' => '/'.$this->pseudoWord(self::LATIN, $n).'/',
                        'audio_path' => null,
                        'is_active' => $active,
                        'meta' => '{}',
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                }
            }
        }
        $this->done($wordRows);

        // Translations (ru and uz) come after the words are in: a child never overtakes its parent in the batches.
        $translations = new PerfBatchWriter('word_translations');
        for ($n = 0; $n < $o->words; $n++) {
            foreach (['ru' => self::CYRILLIC, 'uz' => self::LATIN] as $locale => $syllables) {
                $translations->add([
                    'word_id' => $wordBase + $n,
                    'locale' => $locale,
                    'translation' => $this->pseudoWord($syllables, $n + ($locale === 'uz' ? 17 : 0)),
                    'example_translation' => null,
                    'source' => 'import',
                    'reviewed_at' => null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($translations);

        foreach ($activeByCourse as $c => $active) {
            DB::table('courses')->where('id', $courseBase + $c)->update(['words_count' => $active]);
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Learners
    // ------------------------------------------------------------------------------------------------------------

    private function seedLearners(PerfSeedOptions $o): void
    {
        $this->learnerBase = $this->nextId('learners');
        $span = 120 * self::DAY;
        $rows = new PerfBatchWriter('learners');

        for ($idx = 0; $idx < $o->learners; $idx++) {
            $id = $this->learnerBase + $idx;
            $created = $this->now - intdiv(($o->learners - $idx) * $span, $o->learners);
            $done = $idx % 10 !== 9;
            $lastSeen = $this->now - $this->rng->getInt(0, 60 * self::DAY);
            $streak = $done ? $this->rng->getInt(0, 40) : 0;

            $rows->add([
                'id' => $id,
                'telegram_user_id' => self::TELEGRAM_USER_BASE + $idx + 1,
                'first_name' => self::FIRST_NAMES[$idx % count(self::FIRST_NAMES)],
                'last_name' => $idx % 4 === 0 ? null : self::LAST_NAMES[intdiv($idx, 7) % count(self::LAST_NAMES)],
                'username' => $idx % 10 < 3 ? null : 'perf_user_'.($idx + 1),
                'language_code' => ['ru', 'uz', 'en'][$idx % 3],
                'interface_locale' => $idx % 3 === 1 ? 'uz' : 'ru',
                'timezone' => 'Asia/Tashkent',
                'goal' => self::GOALS[$idx % count(self::GOALS)],
                'level' => self::LEVELS[$idx % count(self::LEVELS)],
                'active_course_id' => $done ? $this->courseIds[$idx % $o->courses] : null,
                'daily_goal' => [10, 20, 30][$idx % 3],
                'reminder_time' => '19:00:00',
                'onboarding_state' => $done ? 'done' : 'new',
                'onboarding_completed_at' => $done ? gmdate('Y-m-d H:i:s', $created + 600) : null,
                'acquisition_source' => ['organic', 'referral', 'group', 'ads'][$idx % 4],
                'referral_code' => 'P'.strtoupper(str_pad(base_convert((string) $id, 10, 36), 6, '0', STR_PAD_LEFT)),
                'streak_current' => $streak,
                'streak_best' => $streak + $this->rng->getInt(0, 20),
                'streak_last_date' => $streak > 0 ? gmdate('Y-m-d', $this->now) : null,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $lastSeen),
                'blocked_at' => $idx % 97 === 0 && $idx > 0 ? gmdate('Y-m-d H:i:s', $lastSeen) : null,
                'meta' => '{}',
                'created_at' => gmdate('Y-m-d H:i:s', $created),
                'updated_at' => gmdate('Y-m-d H:i:s', $lastSeen),
            ]);
        }

        $this->done($rows);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Telegram groups, center members, assignments
    // ------------------------------------------------------------------------------------------------------------

    private function seedGroups(PerfSeedOptions $o): void
    {
        $organizations = $this->organizationCount($o);
        $members = $this->membersPerOrganization($o);
        $groupBase = $this->nextId('telegram_groups');
        $at = $this->at(0);

        // Center groups first (4 per center), then the standalone ones.
        $groups = new PerfBatchWriter('telegram_groups');
        $total = $organizations * self::GROUPS_PER_ORGANIZATION + $o->groups;
        for ($g = 0; $g < $total; $g++) {
            $organization = $g < $organizations * self::GROUPS_PER_ORGANIZATION ? intdiv($g, self::GROUPS_PER_ORGANIZATION) : null;
            $course = $g % $o->courses;
            $this->groups[] = ['id' => $groupBase + $g, 'course' => $course];

            $groups->add([
                'id' => $groupBase + $g,
                'chat_id' => self::CHAT_ID_BASE - $g,
                'title' => $organization === null ? 'Perf group '.($g + 1) : 'Center '.($organization + 1).' group '.(($g % self::GROUPS_PER_ORGANIZATION) + 1),
                'status' => 'active',
                'organization_id' => $organization === null ? null : $this->organizationBase + $organization,
                'course_id' => $this->courseIds[$course],
                'meta' => '{}',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
        $this->done($groups);

        if ($organizations > 0) {
            $this->seedOrganizationMembers($organizations, $members);
        }

        $groupMembers = new PerfBatchWriter('group_members');
        foreach ($this->groups as $g => $group) {
            $organization = $g < $organizations * self::GROUPS_PER_ORGANIZATION ? intdiv($g, self::GROUPS_PER_ORGANIZATION) : null;

            foreach ($this->groupMemberIndexes($o, $g, $organization, $members) as $idx) {
                $groupMembers->add([
                    'telegram_group_id' => $group['id'],
                    'learner_id' => $this->learnerBase + $idx,
                    'status' => 'active',
                    'joined_at' => $this->ago(($idx % 60 + 1) * self::DAY),
                    'left_at' => null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($groupMembers);

        $assignments = new PerfBatchWriter('assignments');
        $assignmentBase = $this->nextId('assignments');
        $assignmentId = $assignmentBase;
        $assigned = [];
        for ($g = 0; $g < $organizations * self::GROUPS_PER_ORGANIZATION; $g++) {
            $organization = intdiv($g, self::GROUPS_PER_ORGANIZATION);
            $lessons = $this->courseLessons[$this->groups[$g]['course']];

            for ($a = 0; $a < self::ASSIGNMENTS_PER_GROUP; $a++) {
                $closed = $a === 0;
                $assignments->add([
                    'id' => $assignmentId,
                    'organization_id' => $this->organizationBase + $organization,
                    'telegram_group_id' => $this->groups[$g]['id'],
                    'lesson_id' => $lessons[($g * 3 + $a * 7) % count($lessons)],
                    'title' => 'Homework '.($a + 1),
                    'due_at' => $this->ago(($closed ? 10 : -3 * ($a + 1)) * self::DAY),
                    'announced_at' => $this->ago(14 * self::DAY),
                    'reminded_at' => null,
                    'status' => $closed ? 'closed' : 'active',
                    'completion_threshold' => 100,
                    'created_by_user_id' => $this->userBase + $organization * 3,
                    'meta' => '{}',
                    'created_at' => $this->ago(14 * self::DAY),
                    'updated_at' => $at,
                ]);
                $assigned[] = [$assignmentId, $g, $organization];
                $assignmentId++;
            }
        }
        $this->done($assignments);

        $results = new PerfBatchWriter('assignment_results');
        foreach ($assigned as [$assignmentId, $g, $organization]) {
            foreach ($this->groupMemberIndexes($o, $g, $organization, $members) as $j => $idx) {
                $progress = [0, 25, 50, 75, 100, 100][($idx + $j) % 6];
                $results->add([
                    'assignment_id' => $assignmentId,
                    'learner_id' => $this->learnerBase + $idx,
                    'progress_pct' => $progress,
                    'completed_at' => $progress === 100 ? $this->ago(2 * self::DAY) : null,
                    'computed_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($results);
    }

    private function seedOrganizationMembers(int $organizations, int $members): void
    {
        $at = $this->at(0);

        $invites = new PerfBatchWriter('organization_invites');
        for ($i = 0; $i < $organizations; $i++) {
            for ($k = 1; $k <= 3; $k++) {
                $invites->add([
                    'organization_id' => $this->organizationBase + $i,
                    'code' => 'perf-'.($i + 1).'-'.$k,
                    'label' => 'Invite '.$k,
                    'telegram_group_id' => null,
                    'max_uses' => null,
                    'uses_count' => 0,
                    'expires_at' => null,
                    'is_active' => true,
                    'created_by_user_id' => $this->userBase + $i * 3,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($invites);

        // Centers take consecutive blocks of learners, so a learner belongs to at most one center.
        $rows = new PerfBatchWriter('organization_members');
        for ($i = 0; $i < $organizations; $i++) {
            for ($m = 0; $m < $members; $m++) {
                $idx = $i * $members + $m;
                $roll = $m % 20;
                $status = $roll === 19 ? 'removed' : ($roll >= 17 ? 'left' : 'active');
                $seated = $status === 'active' && $m % 10 < 9;

                $rows->add([
                    'organization_id' => $this->organizationBase + $i,
                    'learner_id' => $this->learnerBase + $idx,
                    'status' => $status,
                    'seat_state' => $status !== 'active' ? 'none' : ($seated ? 'seated' : 'waitlisted'),
                    'joined_via' => ['invite', 'group', 'admin'][$m % 3],
                    'invite_id' => null,
                    'seated_at' => $seated ? $this->ago(($m % 30 + 1) * self::DAY) : null,
                    'waitlisted_at' => $status === 'active' && ! $seated ? $this->ago(($m % 30 + 1) * self::DAY) : null,
                    'joined_at' => $this->ago(($m % 90 + 1) * self::DAY),
                    'left_at' => $status === 'active' ? null : $this->ago(($m % 10 + 1) * self::DAY),
                    'meta' => '{}',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($rows);
    }

    /**
     * Learner indexes of the members of group $g: a window of {@see self::GROUP_SIZE} learners of the center's block, or
     * a window of the whole population for a standalone group.
     *
     * @return list<int>
     */
    private function groupMemberIndexes(PerfSeedOptions $o, int $g, ?int $organization, int $members): array
    {
        $indexes = [];

        if ($organization !== null) {
            $inCenter = $g % self::GROUPS_PER_ORGANIZATION;
            for ($j = 0; $j < min(self::GROUP_SIZE, $members); $j++) {
                $indexes[] = $organization * $members + (($inCenter * self::GROUP_SIZE + $j) % $members);
            }

            return $indexes;
        }

        $standalone = $g - $this->organizationCount($o) * self::GROUPS_PER_ORGANIZATION;
        for ($j = 0; $j < min(self::GROUP_SIZE, $o->learners); $j++) {
            $indexes[] = ($standalone * self::GROUP_SIZE + $j) % $o->learners;
        }

        return $indexes;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Repetitions, training, stats
    // ------------------------------------------------------------------------------------------------------------

    /**
     * The words learner $idx studies: `[first word id, count]`. Words of a course have consecutive ids, so a window
     * of them is a range; the first {@see PerfSeedOptions::HEAVY_LEARNERS} learners hold a lot.
     *
     * @return array{0: int, 1: int}
     */
    private function repetitionWindow(PerfSeedOptions $o, int $idx): array
    {
        $course = $idx % $o->courses;
        $length = $this->courseWordCount[$course];
        $want = $idx < PerfSeedOptions::HEAVY_LEARNERS
            ? PerfSeedOptions::HEAVY_REPETITIONS
            : max(1, $o->repetitionsPerLearner + ($idx % 7) - 3);
        $count = min($length, $want);

        return [$this->courseFirstWord[$course] + (($idx * 31) % ($length - $count + 1)), $count];
    }

    private function seedRepetitions(PerfSeedOptions $o): void
    {
        $rows = new PerfBatchWriter('word_repetitions');
        $states = ['review', 'review', 'review', 'review', 'review', 'review', 'review', 'learning', 'learning', 'relearning'];

        for ($idx = 0; $idx < $o->learners; $idx++) {
            [$first, $count] = $this->repetitionWindow($o, $idx);
            $learnerId = $this->learnerBase + $idx;

            for ($j = 0; $j < $count; $j++) {
                $repetitions = $this->rng->getInt(0, 8);
                $hard = $this->rng->getInt(1, 100) <= 8;
                $created = $this->now - $this->rng->getInt(1, 90 * self::DAY);
                $reviewed = $repetitions === 0 ? null : $this->now - $this->rng->getInt(0, 30 * self::DAY);

                $rows->add([
                    'learner_id' => $learnerId,
                    'word_id' => $first + $j,
                    'repetitions' => $repetitions,
                    'interval_days' => $repetitions === 0 ? 0 : min(120, 1 << min($repetitions, 7)),
                    'easiness_factor' => 1.3 + $this->rng->getInt(0, 150) / 100,
                    'last_quality' => $repetitions === 0 ? null : $this->rng->getInt(0, 5),
                    'last_reviewed_at' => $reviewed === null ? null : gmdate('Y-m-d H:i:s', $reviewed),
                    'next_review_at' => gmdate('Y-m-d H:i:s', $this->now + $this->rng->getInt(-7 * self::DAY, 21 * self::DAY)),
                    'is_hard' => $hard,
                    'introduced_mode' => 'lesson',
                    'meta' => '{}',
                    'created_at' => gmdate('Y-m-d H:i:s', $created),
                    'updated_at' => gmdate('Y-m-d H:i:s', $reviewed ?? $created),
                    'state' => $repetitions === 0 ? 'new' : $states[$this->rng->getInt(0, 9)],
                    'learning_step' => 0,
                    'lapses' => $hard ? $this->rng->getInt(2, 8) : ($this->rng->getInt(1, 10) <= 2 ? 1 : 0),
                    'scheduler' => 'sm2',
                ]);
            }
        }

        $this->done($rows);
    }

    private function seedTraining(PerfSeedOptions $o): void
    {
        if ($o->reviews === 0) {
            return;
        }

        // Closed daily-review sessions of ten reviews each: the hardest-words widget reads the last 30 days of them.
        $sessions = intdiv($o->reviews + 9, 10);
        $sessionBase = $this->nextId('training_sessions');
        $startedAt = [];
        $rows = new PerfBatchWriter('training_sessions');

        for ($s = 0; $s < $sessions; $s++) {
            $idx = ($s * 7 + 3) % $o->learners;
            $started = $this->now - $this->rng->getInt(60, 29 * self::DAY);
            $startedAt[$s] = $started;

            $rows->add([
                'id' => $sessionBase + $s,
                'learner_id' => $this->learnerBase + $idx,
                'mode' => 'daily_review',
                'source' => 'twa',
                'course_id' => $this->courseIds[$idx % $o->courses],
                'status' => 'closed',
                'started_at' => gmdate('Y-m-d H:i:s', $started),
                'ended_at' => gmdate('Y-m-d H:i:s', $started + 300),
                'expires_at' => gmdate('Y-m-d H:i:s', $started + self::DAY),
                'done_reason' => ['nothing_due', 'session_cap', 'lesson_complete'][$s % 3],
                'created_at' => gmdate('Y-m-d H:i:s', $started),
                'updated_at' => gmdate('Y-m-d H:i:s', $started + 300),
            ]);
        }
        $this->done($rows);

        $reviews = new PerfBatchWriter('training_reviews');
        for ($s = 0; $s < $sessions; $s++) {
            $idx = ($s * 7 + 3) % $o->learners;
            [$first, $count] = $this->repetitionWindow($o, $idx);

            for ($j = 0; $j < 10; $j++) {
                $wordId = $first + (($s + $j) % $count);
                $low = $this->rng->getInt(1, 100) <= ($wordId % 11 === 0 ? 60 : 12);

                $reviews->add([
                    'training_session_id' => $sessionBase + $s,
                    'learner_id' => $this->learnerBase + $idx,
                    'word_id' => $wordId,
                    'quality' => $low ? $this->rng->getInt(0, 2) : $this->rng->getInt(3, 5),
                    'time_spent_ms' => $this->rng->getInt(1500, 9000),
                    'created_at' => gmdate('Y-m-d H:i:s', $startedAt[$s] + $j * 20),
                    'exercise' => 'flash',
                    'scheduler' => 'sm2',
                ]);
            }
        }
        $this->done($reviews);
    }

    private function seedDailyStats(PerfSeedOptions $o): void
    {
        $active = max(1, intdiv($o->learners * 3, 10));
        $rows = new PerfBatchWriter('learner_daily_stats');

        for ($idx = 0; $idx < $active; $idx++) {
            for ($d = 0; $d < 14; $d++) {
                $reviews = $this->rng->getInt(5, 60);
                $at = $this->now - $d * self::DAY;

                $rows->add([
                    'learner_id' => $this->learnerBase + $idx,
                    'date' => gmdate('Y-m-d', $at),
                    'reviews_count' => $reviews,
                    'new_words_count' => $this->rng->getInt(0, 12),
                    'correct_count' => intdiv($reviews * 3, 4),
                    'time_spent_ms' => $reviews * $this->rng->getInt(2000, 6000),
                    'goal_met_at' => $reviews >= 20 ? gmdate('Y-m-d H:i:s', $at) : null,
                    'created_at' => gmdate('Y-m-d H:i:s', $at),
                    'updated_at' => gmdate('Y-m-d H:i:s', $at),
                ]);
            }
        }

        $this->done($rows);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Billing
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Payer slot $slot: the first `payers` slots are learners, the next ones the centers (one paid order each).
     *
     * @return array{type: string, id: int, provider: string, currency: string, amount: int, period: string, tier: ?string, via: string}
     */
    private function payer(PerfSeedOptions $o, int $slot): array
    {
        if ($slot >= $o->payers) {
            return ['type' => 'organization', 'id' => $this->organizationBase + ($slot - $o->payers), 'provider' => 'payme', 'currency' => 'UZS', 'amount' => 12_900_000, 'period' => 'month', 'tier' => 'tutor', 'via' => 'org_panel'];
        }

        $stride = max(1, intdiv($o->learners, max(1, $o->payers)));
        $roll = $slot % 10;
        $provider = $roll < 5 ? 'telegram_stars' : ($roll < 8 ? 'payme' : 'click');
        $year = $slot % 5 === 4;

        return [
            'type' => 'learner',
            'id' => $this->learnerBase + $slot * $stride,
            'provider' => $provider,
            'currency' => $provider === 'telegram_stars' ? 'XTR' : 'UZS',
            'amount' => $provider === 'telegram_stars' ? ($year ? 1_200 : 150) : ($year ? 24_900_000 : 2_900_000),
            'period' => $year ? 'year' : 'month',
            'tier' => null,
            'via' => 'twa',
        ];
    }

    private function seedBilling(PerfSeedOptions $o): void
    {
        $slots = $o->payers + $this->organizationCount($o);

        if ($slots === 0) {
            return;
        }

        $subscriptionBase = $this->nextId('subscriptions');
        $orderBase = $this->nextId('payment_orders');
        $transactionBase = $this->nextId('provider_transactions');
        $paymentBase = $this->nextId('payments');
        $extraOrders = intdiv($o->payers, 2);
        $at = $this->at(0);

        $subscriptions = new PerfBatchWriter('subscriptions');
        for ($s = 0; $s < $slots; $s++) {
            $payer = $this->payer($o, $s);
            $status = $payer['type'] === 'organization' ? 'active' : ($s % 20 < 13 ? 'active' : ($s % 20 < 18 ? 'expired' : 'canceled'));
            $starts = $this->now - $this->rng->getInt(1, 200 * self::DAY);
            $ends = $status === 'expired' ? $this->now - $this->rng->getInt(1, 60 * self::DAY) : $this->now + $this->rng->getInt(1, 30 * self::DAY);

            $subscriptions->add([
                'id' => $subscriptionBase + $s,
                'subscriber_type' => $payer['type'],
                'subscriber_id' => $payer['id'],
                'plan' => 'pro',
                'tier' => $payer['tier'],
                'status' => $status,
                'provider' => $payer['provider'],
                'provider_ref' => null,
                'starts_at' => gmdate('Y-m-d H:i:s', $starts),
                'current_period_ends_at' => gmdate('Y-m-d H:i:s', $ends),
                'grace_until' => null,
                'canceled_at' => $status === 'canceled' ? gmdate('Y-m-d H:i:s', $starts + self::DAY) : null,
                'ended_at' => $status === 'expired' ? gmdate('Y-m-d H:i:s', $ends) : null,
                'seats_limit' => $payer['type'] === 'organization' ? 300 : null,
                'granted_by_user_id' => null,
                'note' => null,
                'meta' => '{}',
                'created_at' => gmdate('Y-m-d H:i:s', $starts),
                'updated_at' => $at,
            ]);
        }
        $this->done($subscriptions);

        // One paid order per payer slot, then the unpaid ones (pending / expired / canceled / failed) of some payers.
        $orders = new PerfBatchWriter('payment_orders');
        for ($n = 0; $n < $slots + $extraOrders; $n++) {
            $paid = $n < $slots;
            $payer = $this->payer($o, $paid ? $n : ($n - $slots) * 2);
            $status = $paid ? 'paid' : ['pending', 'expired', 'canceled', 'failed'][($n - $slots) % 4];
            $created = $this->now - $this->rng->getInt(1, 200 * self::DAY);

            $orders->add([
                'id' => $orderBase + $n,
                'token' => substr(hash('xxh128', 'perf-order-'.$n), 0, 32),
                'subscriber_type' => $payer['type'],
                'subscriber_id' => $payer['id'],
                'plan' => 'pro',
                'tier' => $payer['tier'],
                'period' => $payer['period'],
                'provider' => $payer['provider'],
                'amount' => $payer['amount'],
                'list_amount' => $payer['amount'],
                'currency' => $payer['currency'],
                'offer_id' => null,
                'status' => $status,
                'provider_ref' => null,
                'expires_at' => gmdate('Y-m-d H:i:s', $created + self::DAY),
                'paid_at' => $paid ? gmdate('Y-m-d H:i:s', $created + 120) : null,
                'subscription_id' => $paid ? $subscriptionBase + $n : null,
                'created_via' => $payer['via'],
                'meta' => '{}',
                'created_at' => gmdate('Y-m-d H:i:s', $created),
                'updated_at' => gmdate('Y-m-d H:i:s', $created),
            ]);
        }
        $this->done($orders);

        // Payme and Click keep their own transaction per paid order (Telegram Stars do not).
        $transactions = new PerfBatchWriter('provider_transactions');
        $transactionOfSlot = [];
        for ($s = 0; $s < $slots; $s++) {
            $payer = $this->payer($o, $s);
            if ($payer['provider'] === 'telegram_stars') {
                continue;
            }

            $id = $transactionBase + count($transactionOfSlot);
            $transactionOfSlot[$s] = $id;
            $time = $this->now - $this->rng->getInt(1, 200 * self::DAY);
            $reversed = $s % 20 === 19;

            $transactions->add([
                'id' => $id,
                'provider' => $payer['provider'],
                'provider_transaction_id' => 'perf-ptx-'.$s,
                'payment_order_id' => $orderBase + $s,
                'state' => $reversed ? -2 : 2,
                'amount' => $payer['amount'],
                'provider_time' => $time * 1000,
                'created_at_provider' => gmdate('Y-m-d H:i:s', $time),
                'performed_at' => gmdate('Y-m-d H:i:s', $time + 60),
                'canceled_at' => $reversed ? gmdate('Y-m-d H:i:s', $time + self::DAY) : null,
                'perform_time' => ($time + 60) * 1000,
                'cancel_time' => $reversed ? ($time + self::DAY) * 1000 : null,
                'cancel_reason' => $reversed ? 5 : null,
                'raw' => $this->providerJson($payer['provider'], 'PerformTransaction', $s),
                'history' => '[]',
                'created_at' => gmdate('Y-m-d H:i:s', $time),
                'updated_at' => gmdate('Y-m-d H:i:s', $time + 60),
            ]);
        }
        $this->done($transactions);

        // Charges: one per paid order (a reversed Payme/Click one is "refunded"); renewals for every third payer;
        // then the refund rows that point back at the charges.
        $payments = new PerfBatchWriter('payments');
        for ($s = 0; $s < $slots; $s++) {
            $payments->add($this->paymentRow($o, $s, $paymentBase + $s, 'charge', $s % 20 === 19 ? 'refunded' : 'succeeded', $orderBase + $s, $transactionOfSlot[$s] ?? null, $subscriptionBase + $s, null, 'chg'));
        }
        $this->done($payments);

        $renewals = new PerfBatchWriter('payments');
        $renewalOf = [];
        for ($s = 0; $s < $slots; $s += 3) {
            $id = $paymentBase + $slots + count($renewalOf);
            $renewalOf[$s] = $id;
            $renewals->add($this->paymentRow($o, $s, $id, 'renewal', 'succeeded', null, null, $subscriptionBase + $s, null, 'ren'));
        }
        $this->done($renewals);

        $refunds = new PerfBatchWriter('payments');
        $next = $paymentBase + $slots + count($renewalOf);
        for ($s = 19; $s < $slots; $s += 20) {
            $refunds->add($this->paymentRow($o, $s, $next++, 'refund', 'succeeded', $orderBase + $s, $transactionOfSlot[$s] ?? null, $subscriptionBase + $s, $paymentBase + $s, 'ref'));
        }
        $this->done($refunds);
    }

    /** @return array<string, mixed> */
    private function paymentRow(PerfSeedOptions $o, int $slot, int $id, string $kind, string $status, ?int $orderId, ?int $transactionId, int $subscriptionId, ?int $refundedPaymentId, string $tag): array
    {
        $payer = $this->payer($o, $slot);
        $at = $this->now - $this->rng->getInt(1, 200 * self::DAY);
        $period = $payer['period'] === 'year' ? 365 : 30;

        return [
            'id' => $id,
            'payment_order_id' => $orderId,
            'provider_transaction_id' => $transactionId,
            'subscription_id' => $subscriptionId,
            'subscriber_type' => $payer['type'],
            'subscriber_id' => $payer['id'],
            'provider' => $payer['provider'],
            'provider_event_id' => 'perf-evt-'.$tag.'-'.$slot,
            'provider_charge_id' => 'perf-'.$tag.'-'.substr(hash('xxh128', $tag.$slot), 0, 18),
            'kind' => $kind,
            'status' => $status,
            'amount' => $payer['amount'],
            'currency' => $payer['currency'],
            'refunded_payment_id' => $refundedPaymentId,
            'period_starts_at' => gmdate('Y-m-d H:i:s', $at),
            'period_ends_at' => gmdate('Y-m-d H:i:s', $at + $period * self::DAY),
            'occurred_at' => gmdate('Y-m-d H:i:s', $at),
            'raw' => $this->providerJson($payer['provider'], $kind, $slot),
            'created_at' => gmdate('Y-m-d H:i:s', $at),
            'updated_at' => gmdate('Y-m-d H:i:s', $at),
        ];
    }

    /** A provider payload of a realistic size (~350 bytes): the lists must not select it (docs/20 §1.4). */
    private function providerJson(string $provider, string $event, int $slot): string
    {
        return sprintf(
            '{"provider":"%s","event":"%s","order":"perf-order-%d","amount":%d,"currency":"UZS","account":{"order_id":"%d","learner":"perf"},"meta":{"ip":"203.0.113.%d","user_agent":"TelegramBot (like TwitterBot)","attempt":%d,"note":"generated by perf:seed"},"signature":"%s"}',
            $provider,
            $event,
            $slot,
            2_900_000,
            $slot,
            $slot % 250,
            1 + $slot % 3,
            hash('xxh128', $provider.$event.$slot),
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // Exams
    // ------------------------------------------------------------------------------------------------------------

    private function seedExams(PerfSeedOptions $o): void
    {
        if ($o->exams === 0) {
            return;
        }

        // A sixth of the exams are personal mock exams, the rest are group exams (when there are groups at all).
        $group = $this->groups === [] ? 0 : $o->exams - intdiv($o->exams, 6);
        $examBase = $this->nextId('exam_sessions');
        $sessions = new PerfBatchWriter('exam_sessions');

        for ($e = 0; $e < $o->exams; $e++) {
            $started = $this->now - $this->rng->getInt(1, 90 * self::DAY);
            $isGroup = $e < $group;
            $idx = ($e * 5 + 1) % $o->learners;

            $sessions->add([
                'id' => $examBase + $e,
                'telegram_group_id' => $isGroup ? $this->groups[$e % count($this->groups)]['id'] : null,
                'lesson_id' => $isGroup ? $this->examLesson($e) : null,
                'started_by_user_id' => $isGroup ? $this->adminId : null,
                'status' => 'closed',
                'started_at' => gmdate('Y-m-d H:i:s', $started),
                'ends_at' => gmdate('Y-m-d H:i:s', $started + 600),
                'closed_at' => gmdate('Y-m-d H:i:s', $started + 600),
                'config' => '{}',
                'created_at' => gmdate('Y-m-d H:i:s', $started),
                'updated_at' => gmdate('Y-m-d H:i:s', $started + 600),
                'leaderboard_posted_at' => $isGroup ? gmdate('Y-m-d H:i:s', $started + 601) : null,
                'learner_id' => $isGroup ? null : $this->learnerBase + $idx,
                'mode' => $isGroup ? 'group_exam' : 'personal_mock',
                'course_id' => $isGroup ? null : $this->courseIds[$idx % $o->courses],
            ]);
        }
        $this->done($sessions);

        $answers = new PerfBatchWriter('exam_answers');
        $results = new PerfBatchWriter('exam_results');

        for ($e = 0; $e < $o->exams; $e++) {
            $isGroup = $e < $group;

            if ($isGroup) {
                $lessonId = $this->examLesson($e);
                $first = $this->lessonFirstWord[$lessonId];
                $questions = min(8, $this->lessonWordCount[$lessonId]);
                $participants = array_map(fn (int $j): int => ($e * 13 + $j) % $o->learners, range(0, min(6, $o->learners) - 1));
            } else {
                $idx = ($e * 5 + 1) % $o->learners;
                $course = $idx % $o->courses;
                $questions = min(20, $this->courseWordCount[$course]);
                $first = $this->courseFirstWord[$course] + ($e % ($this->courseWordCount[$course] - $questions + 1));
                $participants = [$idx];
            }

            $scores = [];
            foreach ($participants as $idx) {
                $correct = 0;
                $score = 0;

                for ($q = 0; $q < $questions; $q++) {
                    $right = $this->rng->getInt(1, 100) <= 70;
                    $points = $right ? $this->rng->getInt(6, 10) : 0;
                    $correct += $right ? 1 : 0;
                    $score += $points;

                    $answers->add([
                        'exam_session_id' => $examBase + $e,
                        'learner_id' => $this->learnerBase + $idx,
                        'word_id' => $first + $q,
                        'selected_translation' => 'perf-'.($first + $q),
                        'is_correct' => $right,
                        'score' => $points,
                        'time_spent_ms' => $this->rng->getInt(1000, 9000),
                        'answered_at' => gmdate('Y-m-d H:i:s', $this->now - $this->rng->getInt(1, 90 * self::DAY)),
                    ]);
                }

                $scores[$idx] = [$score, $correct];
            }

            uasort($scores, fn (array $a, array $b): int => $b[0] <=> $a[0]);
            $rank = 1;
            foreach ($scores as $idx => [$score, $correct]) {
                $results->add([
                    'exam_session_id' => $examBase + $e,
                    'learner_id' => $this->learnerBase + $idx,
                    'total_score' => $score,
                    'correct_count' => $correct,
                    'total_count' => $questions,
                    'rank' => $rank++,
                    'created_at' => $this->at(0),
                    'updated_at' => $this->at(0),
                ]);
            }
        }
        $this->done($answers);
        $this->done($results);
    }

    /** The lesson a group exam is held on: one of the lessons of the course of its group. */
    private function examLesson(int $e): int
    {
        $lessons = $this->courseLessons[$this->groups[$e % count($this->groups)]['course']];

        return $lessons[$e % count($lessons)];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Events, league, friendships
    // ------------------------------------------------------------------------------------------------------------

    private function seedEvents(PerfSeedOptions $o): void
    {
        if ($o->events === 0) {
            return;
        }

        $names = [];
        foreach (self::EVENTS as $name => $weight) {
            array_push($names, ...array_fill(0, $weight, $name));
        }

        $rows = new PerfBatchWriter('product_events');
        for ($i = 0; $i < $o->events; $i++) {
            $rows->add([
                'name' => $names[$this->rng->getInt(0, count($names) - 1)],
                'learner_id' => $this->learnerBase + $this->rng->getInt(0, $o->learners - 1),
                'telegram_group_id' => null,
                'user_id' => null,
                'props' => '{}',
                'occurred_at' => gmdate('Y-m-d H:i:s', $this->now - $this->rng->getInt(0, 60 * self::DAY)),
            ]);
        }

        $this->done($rows);
    }

    private function seedSocial(PerfSeedOptions $o): void
    {
        $at = $this->at(0);

        // The open league week (Monday in the league's time zone) with groups of 30 for a fifth of the learners.
        $monday = CarbonImmutable::createFromTimestampUTC($this->now)
            ->setTimezone(new DateTimeZone((string) config('leagues.timezone', 'Asia/Tashkent')))
            ->startOfWeek(CarbonInterface::MONDAY);

        $weekId = DB::table('league_weeks')->where('starts_on', $monday->toDateString())->value('id');
        if ($weekId === null) {
            $weekId = $this->nextId('league_weeks');
            DB::table('league_weeks')->insert([
                'id' => $weekId,
                'starts_on' => $monday->toDateString(),
                'ends_on' => $monday->addDays(6)->toDateString(),
                'status' => 'open',
                'closed_at' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $this->counts['league_weeks'] = 1;
        }

        $members = max(1, intdiv($o->learners, 5));
        $groupCount = intdiv($members + 29, 30);
        $groupBase = $this->nextId('league_groups');

        $groups = new PerfBatchWriter('league_groups');
        for ($g = 0; $g < $groupCount; $g++) {
            $groups->add([
                'id' => $groupBase + $g,
                'league_week_id' => (int) $weekId,
                'tier' => 1 + $g % 2,
                'capacity' => 30,
                'members_count' => min(30, $members - $g * 30),
                'created_at' => $at,
                'updated_at' => $at,
                'closed_at' => null,
            ]);
        }
        $this->done($groups);

        $rows = new PerfBatchWriter('league_members');
        for ($m = 0; $m < $members; $m++) {
            $g = intdiv($m, 30);
            $rows->add([
                'league_group_id' => $groupBase + $g,
                'league_week_id' => (int) $weekId,
                'learner_id' => $this->learnerBase + $m,
                'tier_before' => 1 + $g % 2,
                'final_points' => null,
                'final_rank' => null,
                'result' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
        $this->done($rows);

        // Friendships: one row per pair with learner_id < friend_id. Learner 1 gets friends 2, 8 and 14, and so on.
        $friends = new PerfBatchWriter('friendships');
        for ($a = 0; $a < $o->learners; $a++) {
            foreach ([[1, 2], [7, 4], [13, 6]] as [$distance, $every]) {
                if ($a % $every !== 0 || $a + $distance >= $o->learners) {
                    continue;
                }

                $accepted = ($a + $distance) % 7 !== 0;
                $friends->add([
                    'learner_id' => $this->learnerBase + $a,
                    'friend_id' => $this->learnerBase + $a + $distance,
                    'status' => $accepted ? 'accepted' : 'pending',
                    'requested_by_learner_id' => $this->learnerBase + $a,
                    'accepted_at' => $accepted ? $this->ago(($a % 40 + 1) * self::DAY) : null,
                    'created_at' => $this->ago(($a % 40 + 2) * self::DAY),
                    'updated_at' => $at,
                ]);
            }
        }
        $this->done($friends);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    /** After the explicit ids: the sequences of PostgreSQL continue behind them. */
    private function finish(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::SEQUENCES as $table) {
            $max = (int) DB::table($table)->max('id');
            DB::select('select setval(pg_get_serial_sequence(?, ?), ?)', [$table, 'id', max(1, $max)]);
        }
    }

    private function organizationCount(PerfSeedOptions $o): int
    {
        return min($o->organizations, $o->learners);
    }

    /** Members per center: the requested number, but the centers together cannot hold more learners than exist. */
    private function membersPerOrganization(PerfSeedOptions $o): int
    {
        $organizations = $this->organizationCount($o);

        return $organizations === 0 ? 0 : max(1, min($o->membersPerOrganization, intdiv($o->learners, $organizations)));
    }

    private function nextId(string $table): int
    {
        return $this->nextIds[$table] ??= (int) DB::table($table)->max('id') + 1;
    }

    private function done(PerfBatchWriter $writer): void
    {
        $this->counts[$writer->table()] = ($this->counts[$writer->table()] ?? 0) + $writer->close();
    }

    /** "now + $seconds" as a timestamp literal. */
    private function at(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $seconds);
    }

    /** "now - $seconds" as a timestamp literal. */
    private function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $this->now - $seconds);
    }

    /**
     * @param  list<string>  $syllables
     */
    private function pseudoWord(array $syllables, int $n): string
    {
        $base = count($syllables);
        $word = '';

        do {
            $word .= $syllables[$n % $base];
            $n = intdiv($n, $base);
        } while ($n > 0);

        return $word;
    }
}
