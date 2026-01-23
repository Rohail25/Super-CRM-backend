<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CompanyProjectAccess extends Model
{
    use HasFactory;

    protected $table = 'company_project_access';

    protected $fillable = [
        'company_id',
        'project_id',
        'api_credentials',
        'external_company_id',
        'external_account_data',
        'status',
        'approved_at',
        'approved_by',
        'signup_request_data',
        'last_sync_at',
        'last_error',
        'retry_count',
        'rate_limit_per_minute',
        'rate_limit_per_hour',
        'circuit_breaker_state',
        'circuit_breaker_failures',
        'circuit_breaker_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'api_credentials' => 'array',
            'external_account_data' => 'array',
            'signup_request_data' => 'array',
            'approved_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'circuit_breaker_reset_at' => 'datetime',
            'retry_count' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'rate_limit_per_hour' => 'integer',
            'circuit_breaker_failures' => 'integer',
        ];
    }

    /**
     * Get the company that owns the access.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the project for the access.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user who approved the access.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the project users for this access.
     */
    public function projectUsers()
    {
        return $this->hasMany(CompanyProjectUser::class);
    }

    /**
     * Get the SSO token usages for this access.
     */
    public function ssoTokenUsages()
    {
        return $this->hasMany(SSOTokenUsage::class);
    }

    /**
     * Get the API integration logs for this access.
     */
    public function apiLogs()
    {
        return $this->hasMany(ApiIntegrationLog::class);
    }

    /**
     * Get encrypted API credentials.
     */
    public function getDecryptedApiCredentials(): ?array
    {
        if (!$this->api_credentials) {
            return null;
        }

        $decrypted = [];
        foreach ($this->api_credentials as $key => $value) {
            try {
                $decrypted[$key] = Crypt::decryptString($value);
            } catch (\Exception $e) {
                $decrypted[$key] = $value; // If decryption fails, return as-is
            }
        }

        return $decrypted;
    }

    /**
     * Set encrypted API credentials.
     */
    public function setEncryptedApiCredentials(array $credentials): void
    {
        $encrypted = [];
        foreach ($credentials as $key => $value) {
            $encrypted[$key] = Crypt::encryptString($value);
        }
        $this->api_credentials = $encrypted;
    }
}
