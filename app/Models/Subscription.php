<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'subscription_plan_id',
        'stripe_customer_id',
        'stripe_payment_intent_id', // Track payment for this period
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Get the company that owns the subscription.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the subscription plan.
     */
    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']);
    }

    /**
     * Check if subscription is past due.
     */
    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    /**
     * Check if subscription will cancel at period end.
     */
    public function willCancelAtPeriodEnd(): bool
    {
        return $this->cancel_at_period_end;
    }

    /**
     * Get days until renewal.
     */
    public function daysUntilRenewal(): int
    {
        if (!$this->current_period_end) {
            return 0;
        }
        
        return max(0, now()->diffInDays($this->current_period_end, false));
    }

    /**
     * Check if subscription is on trial.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Scope a query to only include active subscriptions.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'trialing']);
    }

    /**
     * Scope a query to only include past due subscriptions.
     */
    public function scopePastDue($query)
    {
        return $query->where('status', 'past_due');
    }
}

