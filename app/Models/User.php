<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'plain_password',
        'role',
        'permissions',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'plain_password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    /**
     * Get the company that owns the user.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the project user mappings.
     */
    public function projectUsers()
    {
        return $this->hasMany(CompanyProjectUser::class);
    }

    /**
     * Check if user is super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if user is company admin.
     */
    public function isCompanyAdmin(): bool
    {
        return $this->role === 'company_admin';
    }

    /**
     * Check if user has permission.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $permissions = $this->permissions ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    /**
     * Check if user has access to a project.
     * Super admin has access to all projects.
     * Other users only have access to projects assigned to their company.
     */
    public function hasProjectAccess(int $projectId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return \App\Models\CompanyProjectAccess::where('company_id', $this->company_id)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Get accessible project IDs for the user.
     * Super admin gets all project IDs.
     * Other users get only projects assigned to their company.
     */
    public function getAccessibleProjectIds(): array
    {
        if ($this->isSuperAdmin()) {
            return \App\Models\Project::pluck('id')->toArray();
        }

        return \App\Models\CompanyProjectAccess::where('company_id', $this->company_id)
            ->where('status', 'active')
            ->pluck('project_id')
            ->toArray();
    }

    /**
     * Get plain password (decrypted) for external API use.
     * Returns null if not stored.
     */
    public function getPlainPassword(): ?string
    {
        if (!$this->plain_password) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($this->plain_password);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to decrypt plain password', [
                'user_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Set plain password (encrypted for storage).
     */
    public function setPlainPassword(string $password): void
    {
        $this->plain_password = \Illuminate\Support\Facades\Crypt::encryptString($password);
    }
}
