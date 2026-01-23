<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'integration_type',
        'api_base_url',
        'api_auth_type',
        'api_key',
        'api_secret',
        'api_signup_endpoint',
        'api_login_endpoint',
        'api_sso_endpoint',
        'admin_panel_url',
        'iframe_width',
        'iframe_height',
        'iframe_sandbox',
        'sso_enabled',
        'sso_method',
        'sso_token_expiry',
        'sso_redirect_url',
        'sso_callback_url',
        'requires_password_storage',
        'is_legacy',
        'driver_class',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sso_enabled' => 'boolean',
            'requires_password_storage' => 'boolean',
            'is_legacy' => 'boolean',
            'is_active' => 'boolean',
            'sso_token_expiry' => 'integer',
        ];
    }

    /**
     * Get the company accesses for the project.
     */
    public function companyAccesses()
    {
        return $this->hasMany(CompanyProjectAccess::class);
    }

    /**
     * Get the API integration logs for the project.
     */
    public function apiLogs()
    {
        return $this->hasMany(ApiIntegrationLog::class);
    }

    /**
     * Get the SSO token usages for the project.
     */
    public function ssoTokenUsages()
    {
        return $this->hasMany(SSOTokenUsage::class);
    }

    /**
     * Scope a query to only include active projects.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
