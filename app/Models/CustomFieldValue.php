<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CustomFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'custom_field_id',
        'fieldable_type',
        'fieldable_id',
        'value',
        'json_value',
    ];

    protected function casts(): array
    {
        return [
            'json_value' => 'array',
        ];
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    public function fieldable(): MorphTo
    {
        return $this->morphTo();
    }
}
