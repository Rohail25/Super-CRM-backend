<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted()
    {
        static::addGlobalScope('company', function (Builder $query) {
            $user = auth()->user();
            if ($user && !$user->isSuperAdmin() && $user->company_id) {
                $query->where('company_id', $user->company_id);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'created_by',
        'assigned_to',
        'taskable_type',
        'taskable_id',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'start_date',
        'completed_at',
        'progress',
        'estimated_hours',
        'actual_hours',
        'is_recurring',
        'recurrence_pattern',
        'recurrence_config',
        'parent_task_id',
        'reminders',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
            'start_date' => 'datetime',
            'completed_at' => 'datetime',
            'progress' => 'integer',
            'estimated_hours' => 'integer',
            'actual_hours' => 'integer',
            'is_recurring' => 'boolean',
            'recurrence_config' => 'array',
            'reminders' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks()
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->where('status', '!=', 'completed');
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }
}
