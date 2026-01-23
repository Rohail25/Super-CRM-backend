<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SSOTokenUsage extends Model
{
    use HasFactory;

    protected $table = 'sso_token_usage';

    protected $fillable = [
        'jti',
        'company_project_access_id',
        'user_id',
        'project_id',
        'issued_at',
        'expires_at',
        'used_at',
        'ip_address',
        'user_agent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
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
     * Get the user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the project.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Check if token is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if token is used.
     */
    public function isUsed(): bool
    {
        return $this->status === 'used';
    }
}
