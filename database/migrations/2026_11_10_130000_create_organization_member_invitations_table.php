<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations of learners by the center's staff move out of `organization_members` into their own table (review of the
 * audit 2026-11-10 M2, finding B1). A row of `organization_members` with `status = invited` / `declined` carried the learner —
 * their name, username and activity were shown in the cabinet before any consent, and the absence of a row after "Add" told
 * the staff that no such learner exists. Now EVERY attempt is a row here, found learner or not: the cabinet shows only what the
 * staff member typed (`invited_handle`), the date and "waiting" / "expired"; `learner_id`, `dm_sent_at` and `declined_at` are
 * internal — the bot and the service read them, the cabinet never does. `organization_members` keeps real memberships only
 * (`active` / `left` / `removed`); `invited_at` / `invited_by_user_id` leave with the statuses.
 *
 * Data: `invited` rows become open invitations (handle = the learner's `@username`, or their Telegram id), `declined` rows —
 * declined ones; both leave `organization_members`. `down()` brings the columns back and recreates `invited` rows for the open
 * invitations that name a learner who is not a member (a handle that matched nobody has no row to go back to).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_member_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // What the staff member typed, normalised: `@username` (lower case) or the digits of a Telegram id.
            $table->string('invited_handle', 65);
            // Internal: the learner the handle matched at the time, if any. Never shown in the cabinet.
            $table->foreignId('learner_id')->nullable()->constrained('learners')->nullOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('dm_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'learner_id']);
            $table->index(['organization_id', 'invited_handle']);
        });

        $this->moveOffersOut();

        Schema::table('organization_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by_user_id');
            $table->dropColumn('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('organization_members', function (Blueprint $table): void {
            $table->timestamp('invited_at')->nullable()->after('left_at');
            $table->foreignId('invited_by_user_id')->nullable()->after('invited_at')->constrained('users')->nullOnDelete();
        });

        $this->moveOffersBack();

        Schema::dropIfExists('organization_member_invitations');
    }

    /** `invited` / `declined` rows of `organization_members` become invitations and leave the memberships. */
    private function moveOffersOut(): void
    {
        $ttlDays = max(1, (int) config('organizations.member_invite.ttl_days', 7));

        DB::table('organization_members as om')
            ->join('learners as l', 'l.id', '=', 'om.learner_id')
            ->whereIn('om.status', ['invited', 'declined'])
            ->orderBy('om.id')
            ->select(['om.id', 'om.organization_id', 'om.learner_id', 'om.status', 'om.invited_at', 'om.joined_at', 'om.left_at', 'om.invited_by_user_id', 'l.username', 'l.telegram_user_id'])
            ->chunk(500, function ($rows) use ($ttlDays): void {
                $invitations = [];

                foreach ($rows as $row) {
                    $since = $row->invited_at ?? $row->joined_at;
                    $handle = is_string($row->username) && $row->username !== '' ? '@'.strtolower($row->username) : (string) $row->telegram_user_id;

                    $invitations[] = [
                        'organization_id' => $row->organization_id,
                        'invited_handle' => $handle,
                        'learner_id' => $row->learner_id,
                        'invited_by_user_id' => $row->invited_by_user_id,
                        'expires_at' => date('Y-m-d H:i:s', strtotime((string) $since) + $ttlDays * 86400),
                        'dm_sent_at' => $since,
                        'accepted_at' => null,
                        'declined_at' => $row->status === 'declined' ? ($row->left_at ?? $since) : null,
                        'revoked_at' => null,
                        'created_at' => $since,
                        'updated_at' => $since,
                    ];
                }

                DB::table('organization_member_invitations')->insert($invitations);
            });

        DB::table('organization_members')->whereIn('status', ['invited', 'declined'])->delete();
    }

    /** Open invitations that name a learner who is not a member of the center go back as `invited` rows. */
    private function moveOffersBack(): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s');

        DB::table('organization_member_invitations as i')
            ->whereNotNull('i.learner_id')
            ->whereNull('i.accepted_at')
            ->whereNull('i.revoked_at')
            ->where('i.expires_at', '>', $now)
            ->whereNotExists(fn (Builder $q): Builder => $q->selectRaw('1')
                ->from('organization_members as om')
                ->whereColumn('om.organization_id', 'i.organization_id')
                ->whereColumn('om.learner_id', 'i.learner_id'))
            ->orderBy('i.id')
            ->select(['i.organization_id', 'i.learner_id', 'i.invited_by_user_id', 'i.declined_at', 'i.created_at'])
            ->chunk(500, function ($rows): void {
                $members = [];

                foreach ($rows as $row) {
                    $members[] = [
                        'organization_id' => $row->organization_id,
                        'learner_id' => $row->learner_id,
                        'status' => $row->declined_at === null ? 'invited' : 'declined',
                        'seat_state' => 'none',
                        'joined_via' => 'admin',
                        'invite_id' => null,
                        'seated_at' => null,
                        'waitlisted_at' => null,
                        'joined_at' => $row->created_at,
                        'left_at' => $row->declined_at,
                        'invited_at' => $row->created_at,
                        'invited_by_user_id' => $row->invited_by_user_id,
                        'meta' => '{}',
                        'created_at' => $row->created_at,
                        'updated_at' => $row->created_at,
                    ];
                }

                DB::table('organization_members')->insertOrIgnore($members);
            });
    }
};
