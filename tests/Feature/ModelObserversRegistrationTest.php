<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\GameItem;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\ProviderTransaction;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\WordTranslation;
use App\Observers\CourseTreeObserver;
use App\Observers\GameItemObserver;
use App\Observers\MoneyWidgetsObserver;
use App\Observers\OrganizationObserver;
use App\Observers\OrganizationUserObserver;
use App\Observers\TelegramGroupObserver;
use App\Observers\WordTranslationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;

// The observers used to be registered for every request in AppServiceProvider::boot (~2.5 ms of class loading); now they hang
// on their models as #[ObservedBy] and register when the model is first used. The set must stay exactly this.
dataset('observed models', [
    'TelegramGroup' => [TelegramGroup::class, TelegramGroupObserver::class],
    'Organization' => [Organization::class, OrganizationObserver::class],
    'GameItem' => [GameItem::class, GameItemObserver::class],
    'Payment' => [Payment::class, MoneyWidgetsObserver::class],
    'ProviderTransaction' => [ProviderTransaction::class, MoneyWidgetsObserver::class],
    'Stage' => [Stage::class, CourseTreeObserver::class],
    'Lesson' => [Lesson::class, CourseTreeObserver::class],
    'Course' => [Course::class, CourseTreeObserver::class],
    'WordTranslation' => [WordTranslation::class, WordTranslationObserver::class],
    'OrganizationUser' => [OrganizationUser::class, OrganizationUserObserver::class],
]);

it('declares its observer with #[ObservedBy]', function (string $model, string $observer): void {
    $attributes = (new ReflectionClass($model))->getAttributes(ObservedBy::class);

    expect($attributes)->toHaveCount(1)
        ->and(Arr::wrap($attributes[0]->newInstance()->classes))->toBe([$observer]);
})->with('observed models');

it('registers a listener for every event the observer handles when the model boots', function (string $model, string $observer): void {
    /** @var Model $instance */
    $instance = new $model;
    $events = array_filter($instance->getObservableEvents(), static fn (string $event): bool => method_exists($observer, $event));

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        expect(Event::hasListeners("eloquent.{$event}: {$model}"))->toBeTrue("{$model}: no listener for {$event}");
    }
})->with('observed models');
