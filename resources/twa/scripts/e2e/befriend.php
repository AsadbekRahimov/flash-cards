<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\FriendshipService;
use App\Models\Learner;
use Illuminate\Contracts\Console\Kernel;

/**
 * Makes two demo learners friends through the project's own service (the stand of the browser e2e, docs/16 §14.4):
 *
 *   php resources/twa/scripts/e2e/befriend.php [telegram id of the first] [telegram id of the second]
 *
 * The defaults are 200000001 (Alice) and 200000002 (Bob) of DemoLearnersSeeder. It runs in the environment of the stand
 * (see stand.sh), so it changes the database of the stand and nothing else.
 */
$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ids = [(int) ($argv[1] ?? 200000001), (int) ($argv[2] ?? 200000002)];

/** @var list<Learner> $pair */
$pair = array_map(
    static fn (int $telegramId): Learner => Learner::query()->where('telegram_user_id', $telegramId)->firstOrFail(),
    $ids,
);

$friends = $app->make(FriendshipService::class);
$friendship = $friends->request($pair[0], $pair[1]);
$friendship = $friends->accept($pair[1], $friendship->id);

fwrite(STDOUT, "friendship #{$friendship->id}: learner {$pair[0]->id} and learner {$pair[1]->id} are {$friendship->status}\n");
