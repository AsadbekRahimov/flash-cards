<?php

declare(strict_types=1);

use App\Filament\Pages\ImportLesson;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('renders the import page for admin', function (): void {
    livewire(ImportLesson::class)->assertOk();
});

it('imports a lesson via the Filament page', function (): void {
    $json = file_get_contents(storage_path('app/samples/sample-lesson.json'));
    $file = UploadedFile::fake()->createWithContent('lesson.json', $json);

    livewire(ImportLesson::class)
        ->set('data.file', $file)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Word::count())->toBe(20);
});

it('does not write data on invalid JSON upload', function (): void {
    $file = UploadedFile::fake()->createWithContent('broken.json', 'not-json');

    livewire(ImportLesson::class)
        ->set('data.file', $file)
        ->call('submit');

    expect(Word::count())->toBe(0);
});

it('streams the sample JSON on downloadSample', function (): void {
    Storage::disk('local')->assertExists('samples/sample-lesson.json');

    livewire(ImportLesson::class)
        ->call('downloadSample');
});
