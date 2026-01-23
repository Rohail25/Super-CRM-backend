<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Note extends Model
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
        'noteable_type',
        'noteable_id',
        'content',
        'title',
        'type',
        'is_private',
        'shared_with',
        'is_pinned',
        'is_important',
        'parent_note_id',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'shared_with' => 'array',
            'is_pinned' => 'boolean',
            'is_important' => 'boolean',
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

    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'parent_note_id');
    }

    public function replies()
    {
        return $this->hasMany(Note::class, 'parent_note_id');
    }
}
