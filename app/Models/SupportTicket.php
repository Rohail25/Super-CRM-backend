<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class SupportTicket extends Model
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

        static::creating(function ($ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = static::generateTicketNumber();
            }
        });
    }

    protected $fillable = [
        'company_id',
        'customer_id',
        'created_by',
        'assigned_to',
        'ticket_number',
        'subject',
        'description',
        'status',
        'priority',
        'type',
        'first_response_at',
        'first_response_due_at',
        'resolution_due_at',
        'resolved_at',
        'closed_at',
        'customer_name',
        'customer_email',
        'customer_phone',
        'source',
        'channel',
        'resolution',
        'satisfaction_rating',
        'satisfaction_feedback',
        'tags',
        'category',
    ];

    protected function casts(): array
    {
        return [
            'first_response_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'tags' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public static function generateTicketNumber(): string
    {
        $year = date('Y');
        
        // Find the last ticket number globally (ignoring company scope) matching the current year pattern
        $lastTicket = static::withoutGlobalScopes()
            ->where('ticket_number', 'like', "TKT-$year-%")
            ->orderBy('id', 'desc')
            ->first();
        
        if ($lastTicket) {
            // Extract the numeric part (format: TKT-YYYY-NNNNNN)
            $parts = explode('-', $lastTicket->ticket_number);
            $lastNumber = isset($parts[2]) ? (int) $parts[2] : 0;
            $number = $lastNumber + 1;
        } else {
            $number = 1;
        }
        
        $ticketNumber = 'TKT-' . $year . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
        
        // Collision check loop to ensure uniqueness
        while (static::withoutGlobalScopes()->where('ticket_number', $ticketNumber)->exists()) {
            $number++;
            $ticketNumber = 'TKT-' . $year . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
        }
        
        return $ticketNumber;
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress', 'waiting_customer']);
    }

    public function scopeResolved($query)
    {
        return $query->whereIn('status', ['resolved', 'closed']);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOverdue($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($q2) {
                $q2->whereNotNull('first_response_due_at')
                    ->where('first_response_due_at', '<', now())
                    ->whereNull('first_response_at');
            })->orWhere(function ($q2) {
                $q2->whereNotNull('resolution_due_at')
                    ->where('resolution_due_at', '<', now())
                    ->whereNotIn('status', ['resolved', 'closed']);
            });
        });
    }
}
