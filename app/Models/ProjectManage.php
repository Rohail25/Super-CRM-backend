<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class ProjectManage extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'email',
        'password',
        'plain_password',
    ];

    protected $hidden = [
        'password',
        'plain_password',
    ];

    /**
     * Get the project that owns the manage entry.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Set the password attribute (hash it).
     */
    public function setPasswordAttribute($value)
    {
        // Only hash if a non-empty value is provided
        if (!empty($value) && is_string($value) && strlen(trim($value)) > 0) {
            $this->attributes['password'] = Hash::make($value);
        } elseif (empty($value)) {
            // Don't update password if empty (for updates where password is not changed)
            unset($this->attributes['password']);
        }
    }

    /**
     * Check if the provided password matches the stored hash.
     */
    public function checkPassword($password)
    {
        return Hash::check($password, $this->attributes['password']);
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
                'project_manage_id' => $this->id,
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
