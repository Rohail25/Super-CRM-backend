<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Call extends Model
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
        'user_id',
        'customer_id',
        'opportunity_id',
        'contact_name',
        'contact_phone',
        'source',
        'priority',
        'status',
        'outcome',
        'scheduled_at',
        'started_at',
        'completed_at',
        'duration_seconds',
        'notes',
        'next_action',
        'callback_at',
        'converted_to_opportunity',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'callback_at' => 'datetime',
            'duration_seconds' => 'integer',
            'converted_to_opportunity' => 'boolean',
            'value' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_at', today())
            ->orWhereDate('completed_at', today());
    }

    public function scopeNeedsCallback($query)
    {
        return $query->whereNotNull('callback_at')
            ->where('callback_at', '<=', now()->addHours(24))
            ->where('status', '!=', 'completed');
    }
}
