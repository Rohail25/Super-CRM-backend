<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class Document extends Model
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
        'user_id',
        'documentable_type',
        'documentable_id',
        'name',
        'original_name',
        'path',
        'mime_type',
        'size',
        'extension',
        'category',
        'description',
        'metadata',
        'is_public',
        'access_permissions',
        'version',
        'parent_document_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'access_permissions' => 'array',
            'is_public' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_document_id');
    }

    public function versions()
    {
        return $this->hasMany(Document::class, 'parent_document_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
