<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Opportunity extends Model
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
        'customer_id',
        'project_id',
        'created_by',
        'assigned_to',
        'name',
        'description',
        'stage',
        'value',
        'currency',
        'probability',
        'weighted_value',
        'expected_close_date',
        'closed_at',
        'close_reason',
        'source',
        'campaign',
        'loss_reason',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'weighted_value' => 'decimal:2',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function calculateWeightedValue(): void
    {
        if ($this->value && $this->probability) {
            $this->weighted_value = ($this->value * $this->probability) / 100;
        }
    }

    public function scopeByStage($query, string $stage)
    {
        return $query->where('stage', $stage);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('stage', ['closed_won', 'closed_lost']);
    }

    public function scopeClosed($query)
    {
        return $query->whereIn('stage', ['closed_won', 'closed_lost']);
    }
}
