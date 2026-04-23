<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramGroup extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['chat_id', 'title', 'status', 'meta'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'teacher_groups')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** @return HasMany<TrainingSession, $this> */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    /** @return HasMany<ExamSession, $this> */
    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }
}
