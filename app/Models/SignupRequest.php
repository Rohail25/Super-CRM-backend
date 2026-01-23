<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignupRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'requested_projects',
        'company_data',
        'contact_person',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'api_calls_log',
    ];

    protected function casts(): array
    {
        return [
            'requested_projects' => 'array',
            'company_data' => 'array',
            'contact_person' => 'array',
            'api_calls_log' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Get the company for the signup request.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who reviewed the request.
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scope a query to only include pending requests.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
