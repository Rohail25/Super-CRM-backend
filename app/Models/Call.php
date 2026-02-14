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

    /**
     * Ensure string fields are never null when serialized to JSON (for frontend compatibility)
     * Frontend calls .toLowerCase() on these fields, so they must always be strings
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Ensure all string fields are never null (return empty string instead)
        $stringFields = ['source', 'priority', 'status', 'contact_name', 'contact_phone', 'notes', 'next_action', 'outcome'];
        foreach ($stringFields as $field) {
            if (!isset($array[$field])) {
                // Set default values
                if ($field === 'priority') {
                    $array[$field] = 'medium';
                } elseif ($field === 'status') {
                    $array[$field] = 'scheduled';
                } else {
                    $array[$field] = '';
                }
            } elseif ($array[$field] === null) {
                // Handle null values
                if ($field === 'priority') {
                    $array[$field] = 'medium';
                } elseif ($field === 'status') {
                    $array[$field] = 'scheduled';
                } else {
                    $array[$field] = '';
                }
            } elseif (!is_string($array[$field])) {
                // Convert non-string values to strings
                $array[$field] = (string) $array[$field];
            }
        }
        
        // Also normalize related models if they exist
        if (isset($array['user']) && is_array($array['user'])) {
            $array['user'] = $this->normalizeRelatedModel($array['user'], ['name', 'email', 'role']);
        }
        if (isset($array['customer']) && is_array($array['customer'])) {
            $array['customer'] = $this->normalizeRelatedModel($array['customer'], ['first_name', 'last_name', 'email', 'phone']);
        }
        if (isset($array['opportunity']) && is_array($array['opportunity'])) {
            $array['opportunity'] = $this->normalizeRelatedModel($array['opportunity'], ['name', 'stage', 'source', 'campaign', 'currency']);
        }
        
        return $array;
    }
    
    /**
     * Normalize related model array to ensure string fields are never null
     */
    private function normalizeRelatedModel(array $model, array $stringFields): array
    {
        foreach ($stringFields as $field) {
            if (!isset($model[$field]) || $model[$field] === null) {
                $model[$field] = '';
            } elseif (!is_string($model[$field])) {
                $model[$field] = (string) $model[$field];
            }
        }
        return $model;
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
