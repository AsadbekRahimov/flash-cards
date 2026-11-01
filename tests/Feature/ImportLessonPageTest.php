<?php

declare(strict_types=1);

use App\Filament\Pages\ImportLesson;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\NotificationMailbox;
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
    $json = file_get_contents(storage_path('app/private/samples/sample-lesson.json'));
    $file = UploadedFile::fake()->createWithContent('lesson.json', $json);

    livewire(ImportLesson::class)
        ->set('data.file', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertNotified('Импорт выполнен: новых — 20, обновлено — 0, пропущено — 0.')
        ->assertSee('Отчёт о последнем импорте')
        ->assertSee('Добавлено')
        ->assertSee('Прервано');

    expect(Word::count())->toBe(20);
});

it('does not write data on invalid JSON upload', function (): void {
    $file = UploadedFile::fake()->createWithContent('broken.json', 'not-json');

    livewire(ImportLesson::class)
        ->set('data.file', $file)
        ->call('submit')
        ->assertNotified('Импорт не выполнен — ничего не записано.');

    expect(Word::count())->toBe(0);
});

it('prints the text of a refused file in the notification as text, never as HTML', function (string $text): void {
    // A language key of the file: it lands in the sentence about the unsupported language.
    $json = json_encode([
        'stage' => ['number' => 1, 'title' => 'Beginner'],
        'lesson' => ['number' => 1, 'title' => 'Greetings'],
        'words' => [['word' => 'hello', 'translation' => 'привет', 'translations' => [$text => 'x']]],
    ], JSON_THROW_ON_ERROR);
    $file = UploadedFile::fake()->createWithContent('lesson.json', $json);

    livewire(ImportLesson::class)->set('data.file', $file)->call('submit');

    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();
    $notification = collect($mailbox->pull())->last();
    $body = (string) ($notification['body'] ?? '');
    // Escaped on the way in, and what Filament prints of it (it passes the body through its sanitizer) has no element of the file's.
    expect($body)->toContain(e($text))->not->toContain($text)
        ->and((string) str($body)->sanitizeHtml())->not->toContain('<a ')->not->toContain('<img')->not->toContain('<b>')->toContain('&lt;');
    expect(Word::count())->toBe(0);
})->with([
    'a tag' => ['<b>x</b>'],
    'a link' => ['<a href="https://evil.example/">click</a>'],
    'an image' => ['<img src="https://evil.example/pixel.png">'],
]);

it('streams the sample JSON on downloadSample', function (): void {
    Storage::disk('local')->assertExists('samples/sample-lesson.json');

    livewire(ImportLesson::class)
        ->call('downloadSample');
});

it('hides the import page from teachers (403) and does not import', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $this->actingAs($teacher);

    $this->get('/admin/import-lesson')->assertForbidden();
    expect(ImportLesson::canAccess())->toBeFalse();
});

it('hides the import page from an inactive admin', function (): void {
    $admin = User::factory()->create(['is_active' => false]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    expect(ImportLesson::canAccess())->toBeFalse();
});
