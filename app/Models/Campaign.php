<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Campaign extends Model
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
        'project_id',
        'name',
        'description',
        'image_path',
        'target_link',
        'type',
        'status',
        'start_date',
        'end_date',
        'scheduled_at',
        'budget',
        'spent',
        'currency',
        'payment_status',
        'stripe_payment_intent_id',
        'stripe_checkout_session_id',
        'target_audience',
        'target_criteria',
        'subject',
        'content',
        'content_data',
        'settings',
        'is_active',
        'track_clicks',
        'track_opens',
        'sent_count',
        'delivered_count',
        'opened_count',
        'clicked_count',
        'converted_count',
        'bounced_count',
        'unsubscribed_count',
        'open_rate',
        'click_rate',
        'conversion_rate',
        'roi',
    ];

    protected function casts(): array
    {
        return [
            'target_audience' => 'array',
            'target_criteria' => 'array',
            'content_data' => 'array',
            'settings' => 'array',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'scheduled_at' => 'datetime',
            'budget' => 'decimal:2',
            'spent' => 'decimal:2',
            'is_active' => 'boolean',
            'track_clicks' => 'boolean',
            'track_opens' => 'boolean',
            'open_rate' => 'decimal:2',
            'click_rate' => 'decimal:2',
            'conversion_rate' => 'decimal:2',
            'roi' => 'decimal:2',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Calculate performance metrics.
     */
    public function calculateMetrics(): void
    {
        if ($this->sent_count > 0) {
            $this->open_rate = ($this->opened_count / $this->sent_count) * 100;
            $this->click_rate = ($this->clicked_count / $this->sent_count) * 100;
            $this->conversion_rate = ($this->converted_count / $this->sent_count) * 100;
        }

        if ($this->budget && $this->budget > 0) {
            // Simple ROI calculation - can be enhanced based on revenue from conversions
            $this->roi = (($this->converted_count * 100) / $this->budget) - 100;
        }

        $this->save();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
