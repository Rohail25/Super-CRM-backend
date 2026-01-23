<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'amount',
        'currency',
        'interval',
        'features',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the subscriptions for the plan.
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Check if plan is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Get formatted price.
     */
    public function getFormattedPrice(): string
    {
        $amount = $this->amount / 100; // Convert cents to currency
        $symbol = match($this->currency) {
            'eur' => '€',
            'usd' => '$',
            'gbp' => '£',
            default => $this->currency . ' ',
        };
        
        return $symbol . number_format($amount, 2, ',', '.') . ' / ' . $this->interval;
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

