<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The authored content of the practice (docs/16 §2.2-§2.4): `practice_items` (a task with a stable key),
 * append-only `practice_item_versions` (the text, the audio) and `practice_item_words` (target words).
 *
 * `practice_items.current_version_id` / `published_version_id` have NO foreign key on purpose (a cycle
 * items <-> versions complicates create / `down()` and gains nothing: one service writes them, a test
 * guards them). The enumerations are varchar; on PostgreSQL a CHECK holds them (and the shape of the
 * status: published <=> a published version and not archived), on SQLite the service and the enums do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('type', 12); // collocation | sentence | dictation
            $table->string('key', 64); // the author's stable key: the idempotency of the import
            $table->string('level', 2)->nullable(); // A1..C2
            $table->string('status', 10)->default('draft'); // draft | reviewed | published | archived
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable(); // what the learners get; NULL = never published
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'key']);
            $table->index(['course_id', 'status', 'type']);
            $table->index(['lesson_id', 'status', 'type']);
            $table->index('current_version_id');
            $table->index('published_version_id');
        });

        Schema::create('practice_item_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('practice_items')->cascadeOnDelete();
            $table->smallInteger('number');
            $table->string('state', 10); // draft | reviewed | published | superseded
            $table->jsonb('content'); // texts and structure by type (docs/16 §2.3); never changed after the INSERT
            $table->string('audio_key', 40)->nullable(); // dictation only: the name of `{audio_key}.mp3`
            $table->string('audio_status', 8)->default('none'); // none | pending | ready | failed
            $table->string('audio_error', 100)->nullable();
            $table->timestamp('audio_generated_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('created_at')->useCurrent(); // no updated_at: the audio job changes only the audio columns

            $table->unique(['item_id', 'number']);
        });

        Schema::create('practice_item_words', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('practice_items')->cascadeOnDelete();
            $table->foreignId('word_id')->constrained('words')->cascadeOnDelete();

            $table->unique(['item_id', 'word_id']);
            $table->index('word_id');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            // The admin widget "audio in the queue" and the re-queue scan only the pending versions.
            DB::statement(
                "CREATE INDEX practice_item_versions_audio_pending_idx
                 ON practice_item_versions (audio_status)
                 WHERE audio_status = 'pending'",
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE practice_items ADD CONSTRAINT practice_items_type_check CHECK (type IN ('collocation','sentence','dictation'))");
            DB::statement("ALTER TABLE practice_items ADD CONSTRAINT practice_items_status_check CHECK (status IN ('draft','reviewed','published','archived'))");
            DB::statement(
                "ALTER TABLE practice_items ADD CONSTRAINT practice_items_published_shape_check
                 CHECK ((status = 'published') = (published_version_id IS NOT NULL AND archived_at IS NULL))",
            );
            DB::statement("ALTER TABLE practice_item_versions ADD CONSTRAINT practice_item_versions_state_check CHECK (state IN ('draft','reviewed','published','superseded'))");
            DB::statement("ALTER TABLE practice_item_versions ADD CONSTRAINT practice_item_versions_audio_status_check CHECK (audio_status IN ('none','pending','ready','failed'))");
        }
    }

    public function down(): void
    {
        // Dropping a table drops its constraints and indexes (the partial one included) on every driver.
        Schema::dropIfExists('practice_item_words');
        Schema::dropIfExists('practice_item_versions');
        Schema::dropIfExists('practice_items');
    }
};
