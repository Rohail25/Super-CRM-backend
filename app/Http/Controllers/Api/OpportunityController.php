<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Opportunity;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OpportunityController extends Controller
{
    use HandlesApiErrors;
    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Opportunity::with(['customer', 'project', 'assignee', 'creator']);

        // Filters
        if ($request->has('stage')) {
            $query->where('stage', $request->stage);
        }
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->has('project_id')) {
            $projectId = $request->project_id;
            // For non-super-admin, validate they have access to the project
            if (!$user->isSuperAdmin() && !$user->hasProjectAccess($projectId)) {
                return response()->json([
                    'message' => 'You do not have access to this project',
                ], 403);
            }
            $query->where('project_id', $projectId);
        } elseif (!$user->isSuperAdmin()) {
            
            $accessibleProjectIds = $user->getAccessibleProjectIds();
            $query->where(function($q) use ($accessibleProjectIds) {
                if (!empty($accessibleProjectIds)) {
                    $q->whereIn('project_id', $accessibleProjectIds);
            }
                $q->orWhereNull('project_id');
            });
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
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
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'project_id' => 'nullable|exists:projects,id',
            'assigned_to' => 'nullable|exists:users,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'stage' => ['required', Rule::in(['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost', 'on_hold'])],
            'value' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'probability' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'source' => 'nullable|string|max:255',
            'campaign' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        
        // Validate project access if project_id is provided
        if (isset($validated['project_id']) && !$user->hasProjectAccess($validated['project_id'])) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }
        
        $validated['company_id'] = $user->company_id;
        $validated['created_by'] = $user->id;

        $opportunity = DB::transaction(function () use ($validated) {
            $opportunity = Opportunity::create($validated);
            $opportunity->calculateWeightedValue();
            $opportunity->save();

            $this->activityLogService->logCreated($opportunity);

            return $opportunity;
        });

        return response()->json($opportunity->load(['customer', 'project', 'assignee']), 201);
    }

    public function show(Opportunity $opportunity)
    {
        $this->activityLogService->logViewed($opportunity);
        return response()->json($opportunity->load(['customer', 'project', 'assignee', 'creator', 'tasks', 'notes', 'documents']));
    }

    public function update(Request $request, Opportunity $opportunity)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'project_id' => 'nullable|exists:projects,id',
            'assigned_to' => 'nullable|exists:users,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'stage' => ['sometimes', Rule::in(['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost', 'on_hold'])],
            'value' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'probability' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'source' => 'nullable|string|max:255',
            'campaign' => 'nullable|string|max:255',
            'close_reason' => 'nullable|string',
            'loss_reason' => 'nullable|string',
        ]);

        // Validate project access if project_id is being updated
        if (isset($validated['project_id']) && !$user->hasProjectAccess($validated['project_id'])) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }

        $oldValues = $opportunity->getAttributes();

        $opportunity = DB::transaction(function () use ($opportunity, $validated) {
            $opportunity->update($validated);

            // If stage changed to closed, set closed_at
            if (in_array($opportunity->stage, ['closed_won', 'closed_lost']) && !$opportunity->closed_at) {
                $opportunity->closed_at = now();
            }

            $opportunity->calculateWeightedValue();
            $opportunity->save();

            return $opportunity;
        });

        $this->activityLogService->logUpdated($opportunity, $oldValues, $opportunity->getAttributes());

        return response()->json($opportunity->load(['customer', 'project', 'assignee']));
    }

    public function destroy(Opportunity $opportunity)
    {
        $this->activityLogService->logDeleted($opportunity);
        $opportunity->delete();
        return response()->json(null, 204);
    }

    public function convert(Request $request, Opportunity $opportunity)
    {
        if ($opportunity->stage !== 'closed_won') {
            return response()->json(['error' => 'Only won opportunities can be converted'], 400);
        }

        // Convert opportunity to customer or other entity
        // This is a placeholder - implement based on your business logic
        return response()->json(['message' => 'Opportunity converted successfully']);
    }
}
