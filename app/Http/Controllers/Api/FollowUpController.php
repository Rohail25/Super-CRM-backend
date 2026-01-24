<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\FollowUp;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowUpController extends Controller
{
    use HandlesApiErrors;
    /**
     * Get follow-ups for a specific lead (customer).
     */
    public function index(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $followUps = FollowUp::where('customer_id', $customer->id)
            ->with(['creator', 'assignee', 'opportunity'])
            ->orderBy('scheduled_at', 'asc')
            ->get();

        return response()->json($followUps);
    }

    /**
     * Create a new follow-up for a lead.
     */
    public function store(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Refresh customer to ensure we have latest data
        $customer->refresh();
        
        // Determine company_id based on user role
        $companyId = null;
        
        if ($user->isSuperAdmin()) {
            // Super admin: use customer's company_id, or get from opportunity
            $companyId = $customer->company_id;
            
            // If customer doesn't have company_id, try to get it from their opportunity
            if (!$companyId) {
                $opportunity = $customer->opportunities()->latest()->first();
                if ($opportunity && $opportunity->company_id) {
                    $companyId = $opportunity->company_id;
                    // Update customer with company_id for consistency
                    $customer->update(['company_id' => $companyId]);
                }
            }
            
            // If still no company_id, try to get from request
            if (!$companyId) {
                $companyId = $request->input('company_id');
            }
            
            // If still no company_id, this is an error
            if (!$companyId) {
                return response()->json([
                    'message' => 'Company ID not found. Customer does not have a company_id and no company_id provided in request.',
                    'customer_id' => $customer->id,
                    'customer_company_id' => $customer->company_id,
                ], 400);
            }
        } else {
            // Regular users (including company admin): use their company_id
            // Ensure user's company_id is loaded
            $companyId = $user->company_id;
            
            if (!$companyId) {
                // Try to reload user with company relationship
                $user->refresh();
                $companyId = $user->company_id;
                
                if (!$companyId) {
                    return response()->json([
                        'message' => 'User must belong to a company. Your account does not have a company_id.',
                        'user_id' => $user->id,
                        'user_role' => $user->role,
                    ], 400);
                }
            }
            
            // Check access - customer should belong to same company as user
            if ($customer->company_id && $customer->company_id !== $companyId) {
                return response()->json(['message' => 'Access denied'], 403);
            }
            
            // If customer doesn't have company_id, update it to user's company_id for consistency
            if (!$customer->company_id) {
                $customer->update(['company_id' => $companyId]);
            }
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'type' => 'required|in:call,email,meeting,message,other',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'scheduled_at' => 'required|date',
            'assigned_to' => 'nullable|exists:users,id',
            'opportunity_id' => 'nullable|exists:opportunities,id',
        ]);

        $followUp = FollowUp::create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'opportunity_id' => $validated['opportunity_id'] ?? $customer->opportunities()->open()->latest()->first()?->id,
            'created_by' => $user->id,
            'assigned_to' => $validated['assigned_to'] ?? $user->id,
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
            'type' => $validated['type'],
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'scheduled',
            'scheduled_at' => $validated['scheduled_at'],
        ]);

        return response()->json($followUp->load(['creator', 'assignee', 'opportunity']), 201);
    }

    /**
     * Update a follow-up.
     */
    public function update(Request $request, FollowUp $followUp)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $followUp->company_id !== $user->company_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'notes' => 'nullable|string',
            'type' => 'sometimes|in:call,email,meeting,message,other',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'status' => 'sometimes|in:scheduled,completed,cancelled,overdue',
            'scheduled_at' => 'sometimes|date',
            'completed_at' => 'nullable|date',
            'outcome' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $followUp->update($validated);

        return response()->json($followUp->load(['creator', 'assignee', 'opportunity']));
    }

    /**
     * Mark follow-up as completed.
     */
    public function complete(Request $request, FollowUp $followUp)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $followUp->company_id !== $user->company_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $validated = $request->validate([
            'outcome' => 'nullable|string',
        ]);

        $followUp->markAsCompleted($validated['outcome'] ?? null);

        return response()->json($followUp->load(['creator', 'assignee', 'opportunity']));
    }

    /**
     * Delete a follow-up.
     */
    public function destroy(FollowUp $followUp)
    {
        $user = request()->user();

        // Check access
        if (!$user->isSuperAdmin() && $followUp->company_id !== $user->company_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $followUp->delete();

        return response()->json(['message' => 'Follow-up deleted successfully']);
    }

    /**
     * Get upcoming follow-ups for the authenticated user.
     */
    public function upcoming(Request $request)
    {
        $user = $request->user();
        $days = $request->query('days', 7);

        $followUps = FollowUp::upcoming($days)
            ->where(function ($query) use ($user) {
                $query->where('assigned_to', $user->id)
                      ->orWhere('created_by', $user->id);
            })
            ->with(['customer', 'opportunity', 'creator', 'assignee'])
            ->orderBy('scheduled_at', 'asc')
            ->get();

        return response()->json($followUps);
    }
}
