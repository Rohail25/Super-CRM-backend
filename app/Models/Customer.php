<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Customer extends Model
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
        'email',
        'phone',
        'vat',
        'first_name',
        'last_name',
        'address',
        'notes',
    ];

    /**
     * Get the company that owns the customer.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the opportunities for the customer.
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * Get all tasks for the customer.
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /**
     * Get all notes for the customer.
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    /**
     * Get all documents for the customer.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get the full name attribute.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Scope a query to filter by company.
     */
    public function scopeForCompany($query, ?int $companyId)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query;
    }
}
