<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'headers_json',
        'row_data_json',
        'status',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'headers_json' => 'array',
        'row_data_json' => 'array',
        'sent_at' => 'datetime',
    ];

    /**
     * Get the email address from row_data_json
     */
    public function getEmailAddressAttribute(): ?string
    {
        $data = $this->row_data_json;
        if (is_array($data) && isset($data['email'])) {
            return $data['email'];
        }
        return null;
    }
}
