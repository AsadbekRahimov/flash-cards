<?php

declare(strict_types=1);

use App\Domain\Billing\Exceptions\OfferException;
use App\Domain\Billing\Services\OfferService;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\OffersRelationManager;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Offers on the learner page (docs/13 §11 2B.7): admin only, read-only, "Annul" expires an unused
 * offer at once and is audited.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->learner = Learner::factory()->create();
    $this->props = ['ownerRecord' => $this->learner, 'pageClass' => ViewLearner::class];
});

it('lists only this learner\'s offers', function (): void {
    $open = Offer::factory()->create(['learner_id' => $this->learner->id]);
    $used = Offer::factory()->redeemed()->create(['learner_id' => $this->learner->id, 'kind' => 'campaign']);
    $foreign = Offer::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test(OffersRelationManager::class, $this->props)
        ->assertCanSeeTableRecords([$open, $used])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('annuls an unused offer with an audit record; a used or lapsed one has no Annul', function (): void {
    $open = Offer::factory()->create(['learner_id' => $this->learner->id]);
    $used = Offer::factory()->redeemed()->create(['learner_id' => $this->learner->id, 'kind' => 'campaign']);

    $this->actingAs($this->admin);

    Livewire::test(OffersRelationManager::class, $this->props)
        ->assertTableActionVisible('annul', $open)
        ->assertTableActionHidden('annul', $used)
        ->callTableAction('annul', $open);

    $open->refresh();
    expect($open->isOpenAt(now()->toImmutable()))->toBeFalse()
        ->and($open->redeemed_at)->toBeNull()
        ->and($open->meta['annulled_by'])->toBe($this->admin->id);

    $audit = AuditLog::query()->where('action', 'offer.annulled')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($open->id)
        ->and($audit->meta['learner_id'])->toBe($this->learner->id);

    expect(fn () => app(OfferService::class)->annul($open, $this->admin))->toThrow(OfferException::class);
});

it('is closed to a teacher', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $offer = Offer::factory()->create(['learner_id' => $this->learner->id]);

    $this->actingAs($teacher);

    expect(OffersRelationManager::canViewForRecord($this->learner, ViewLearner::class))->toBeFalse();
    Livewire::test(OffersRelationManager::class, $this->props)->assertForbidden();
    expect(fn () => app(OfferService::class)->annul($offer, $teacher))->toThrow(OfferException::class)
        ->and(AuditLog::query()->where('action', 'offer.annulled')->count())->toBe(0);
});
