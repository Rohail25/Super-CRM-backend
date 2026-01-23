<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class FollowUp extends Model
{
    use HasFactory;

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
        'opportunity_id',
        'created_by',
        'assigned_to',
        'title',
        'notes',
        'type',
        'status',
        'priority',
        'scheduled_at',
        'completed_at',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Check if follow-up is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->status === 'scheduled' && 
               $this->scheduled_at < now() && 
               !$this->completed_at;
    }

    /**
     * Mark follow-up as completed.
     */
    public function markAsCompleted(?string $outcome = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'outcome' => $outcome ?? $this->outcome,
        ]);
    }

    /**
     * Scope for scheduled follow-ups.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope for overdue follow-ups.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'scheduled')
                    ->where('scheduled_at', '<', now());
    }

    /**
     * Scope for upcoming follow-ups.
     */
    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->where('status', 'scheduled')
                    ->whereBetween('scheduled_at', [now(), now()->addDays($days)]);
    }
}
