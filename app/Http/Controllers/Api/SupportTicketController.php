<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = SupportTicket::with(['customer', 'assignee', 'creator']);

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->has('overdue')) {
            $query->overdue();
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('ticket_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        
        $validationRules = [
            'customer_id' => 'nullable|exists:customers,id',
            'assigned_to' => 'nullable|exists:users,id',
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'type' => ['required', Rule::in(['technical', 'billing', 'feature_request', 'bug', 'other'])],
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:255',
            'channel' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'first_response_due_at' => 'nullable|date',
            'resolution_due_at' => 'nullable|date',
        ];

        // Allow company_id for super_admin
        if ($user->isSuperAdmin()) {
            $validationRules['company_id'] = 'required|exists:companies,id';
        }

        $validated = $request->validate($validationRules);

        // Set company_id: use provided one for super_admin, otherwise use user's company_id
        if ($user->isSuperAdmin() && isset($validated['company_id'])) {
            $validated['company_id'] = $validated['company_id'];
        } else {
            $validated['company_id'] = $user->company_id;
        }
        
        $validated['created_by'] = $user->id;
        $validated['status'] = $validated['status'] ?? 'open';

        $ticket = DB::transaction(function () use ($validated) {
            $ticket = SupportTicket::create($validated);
            $this->activityLogService->logCreated($ticket);
            return $ticket;
        });

        return response()->json($ticket->load(['customer', 'assignee', 'creator']), 201);
    }

    public function show(SupportTicket $supportTicket)
    {
        $this->activityLogService->logViewed($supportTicket);
        return response()->json($supportTicket->load(['customer', 'assignee', 'creator', 'notes', 'tasks', 'documents']));
    }

    public function update(Request $request, SupportTicket $supportTicket)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'assigned_to' => 'nullable|exists:users,id',
            'subject' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'type' => ['sometimes', Rule::in(['technical', 'billing', 'feature_request', 'bug', 'other'])],
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:255',
            'channel' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'resolution' => 'nullable|string',
            'first_response_due_at' => 'nullable|date',
            'resolution_due_at' => 'nullable|date',
            'satisfaction_rating' => ['nullable', Rule::in(['very_satisfied', 'satisfied', 'neutral', 'dissatisfied', 'very_dissatisfied'])],
            'satisfaction_feedback' => 'nullable|string',
        ]);

        $oldValues = $supportTicket->getAttributes();

        $ticket = DB::transaction(function () use ($supportTicket, $validated) {
            // Handle status changes
            if (isset($validated['status'])) {
                if ($validated['status'] === 'in_progress' && !$supportTicket->first_response_at) {
                    $validated['first_response_at'] = now();
                }
                if (in_array($validated['status'], ['resolved', 'closed']) && !$supportTicket->resolved_at) {
                    $validated['resolved_at'] = now();
                }
                if ($validated['status'] === 'closed' && !$supportTicket->closed_at) {
                    $validated['closed_at'] = now();
                }
            }

            $supportTicket->update($validated);
            return $supportTicket;
        });

        $this->activityLogService->logUpdated($ticket, $oldValues, $ticket->getAttributes());

        return response()->json($ticket->load(['customer', 'assignee', 'creator']));
    }

    public function destroy(SupportTicket $supportTicket)
    {
        $this->activityLogService->logDeleted($supportTicket);
        $supportTicket->delete();
        return response()->json(null, 204);
    }

    public function assign(Request $request, SupportTicket $supportTicket)
    {
        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $supportTicket->update(['assigned_to' => $validated['assigned_to']]);
        $this->activityLogService->logUpdated($supportTicket, ['assigned_to' => $supportTicket->getOriginal('assigned_to')], ['assigned_to' => $validated['assigned_to']]);

        return response()->json($supportTicket->load(['assignee']));
    }

    public function close(Request $request, SupportTicket $supportTicket)
    {
        $validated = $request->validate([
            'resolution' => 'nullable|string',
        ]);

        $supportTicket->update([
            'status' => 'closed',
            'closed_at' => now(),
            'resolution' => $validated['resolution'] ?? $supportTicket->resolution,
        ]);

        $this->activityLogService->logUpdated($supportTicket, ['status' => $supportTicket->getOriginal('status')], ['status' => 'closed']);

        return response()->json($supportTicket->load(['customer', 'assignee', 'creator']));
    }
}
