<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiIntegrationLog extends Model
{
    use HasFactory;

    protected $table = 'api_integration_logs';

    protected $fillable = [
        'company_project_access_id',
        'project_id',
        'user_id',
        'endpoint',
        'method',
        'request_payload',
        'response_status',
        'response_body',
        'error_message',
        'rate_limit_hit',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_body' => 'array',
            'rate_limit_hit' => 'boolean',
            'duration_ms' => 'integer',
            'response_status' => 'integer',
        ];
    }

    /**
     * Get the company project access.
     */
    public function companyProjectAccess()
    {
        return $this->belongsTo(CompanyProjectAccess::class);
    }

    /**
     * Get the project.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include successful requests.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('response_status', '>=', 200)
            ->where('response_status', '<', 300);
    }

    /**
     * Scope a query to only include failed requests.
     */
    public function scopeFailed($query)
    {
        return $query->where(function ($q) {
            $q->where('response_status', '>=', 400)
                ->orWhereNotNull('error_message');
        });
    }
}
