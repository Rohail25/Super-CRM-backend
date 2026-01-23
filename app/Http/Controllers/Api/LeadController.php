<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Services\CustomerDeduplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function __construct(
        private CustomerDeduplicationService $deduplicationService
    ) {}

    /**
     * Map opportunity stage to lead status
     */
    private function mapStageToStatus(?string $stage): string
    {
        return match($stage) {
            'prospecting', 'qualification' => 'hot',
            'proposal', 'negotiation' => 'warm',
            'closed_won' => 'converted',
            'closed_lost', 'on_hold' => 'cold',
            default => 'cold',
        };
    }

    /**
     * Display a listing of leads.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        // Get customers with their primary opportunity
        // Note: Leads are independent - not tied to projects for now
        $query = Customer::where('company_id', $companyId)
            ->with(['company'])
            ->with(['opportunities' => function ($q) {
                $q->open()->latest()->with(['assignee'])->limit(1);
            }]);

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $customers = $query->latest()->get();

        // Transform to lead format
        $leads = $customers->map(function ($customer) {
            $opportunity = $customer->opportunities->first();
            
            // Get source from opportunity (manual text field, not tied to projects)
            $source = $opportunity?->source ?? 'Direct';

            // Map stage to status
            $status = $this->mapStageToStatus($opportunity?->stage);

            // Get assigned user name
            $assignedTo = $opportunity?->assignee?->name;

            return [
                'id' => $customer->id,
                'name' => $customer->full_name ?: ($customer->first_name . ' ' . $customer->last_name),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'source' => $source,
                'status' => $status,
                'value' => $opportunity?->value ? (float) $opportunity->value : null,
                'assigned_to' => $assignedTo,
                'created_at' => $customer->created_at->format('Y-m-d H:i:s'),
                'opportunity_id' => $opportunity?->id,
            ];
        });

        // Apply status filter
        if ($request->has('status') && $request->status !== 'all') {
            $leads = $leads->filter(function ($lead) use ($request) {
                return $lead['status'] === $request->status;
            })->values();
        }

        // Apply source filter
        if ($request->has('source') && $request->source !== 'all') {
            $leads = $leads->filter(function ($lead) use ($request) {
                return $lead['source'] === $request->source;
            })->values();
        }

        return response()->json([
            'data' => $leads->values(),
            'total' => $leads->count(),
        ]);
    }

    /**
     * Store a newly created lead.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'required|string',
            'source' => 'nullable|string|max:255',
            'status' => 'nullable|in:hot,warm,cold,converted',
            'value' => 'nullable|numeric|min:0',
            'assigned_to' => 'nullable|exists:users,id',
            // 'project_id' => 'nullable|exists:projects,id', // Removed - leads are independent for now
        ]);

        // Map status to opportunity stage
        $stage = match($validated['status'] ?? 'cold') {
            'hot' => 'prospecting',
            'warm' => 'proposal',
            'cold' => 'on_hold',
            'converted' => 'closed_won',
            default => 'prospecting',
        };

        // Split name into first and last name
        $nameParts = explode(' ', $validated['name'], 2);
        $firstName = $nameParts[0] ?? null;
        $lastName = $nameParts[1] ?? null;

        $customerData = [
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];

        $opportunityData = [
            'name' => $validated['name'],
            'stage' => $stage,
            'value' => $validated['value'] ?? null,
            'source' => $validated['source'] ?? null,
            // 'project_id' => $validated['project_id'] ?? null, // Removed - leads independent
            'assigned_to' => $validated['assigned_to'] ?? null,
            'created_by' => $user->id,
        ];

        $result = DB::transaction(function () use ($customerData, $opportunityData, $companyId) {
            // Create or find customer using deduplication service
            $customer = $this->deduplicationService->findOrCreateCustomer($customerData, $companyId);

            // Create opportunity
            $opportunityData['company_id'] = $companyId;
            $opportunityData['customer_id'] = $customer->id;
            $opportunity = Opportunity::create($opportunityData);
            $opportunity->calculateWeightedValue();
            $opportunity->save();

            return [
                'customer' => $customer,
                'opportunity' => $opportunity,
            ];
        });

        // Format response
        $lead = [
            'id' => $result['customer']->id,
            'name' => $result['customer']->full_name,
            'email' => $result['customer']->email,
            'phone' => $result['customer']->phone,
            'source' => $result['opportunity']->source ?? 'Direct',
            'status' => $this->mapStageToStatus($result['opportunity']->stage),
            'value' => $result['opportunity']->value ? (float) $result['opportunity']->value : null,
            'assigned_to' => $result['opportunity']->assignee?->name,
            'created_at' => $result['customer']->created_at->format('Y-m-d H:i:s'),
            'opportunity_id' => $result['opportunity']->id,
        ];

        return response()->json($lead, 201);
    }

    /**
     * Display the specified lead.
     */
    public function show(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $opportunity = $customer->opportunities()->open()->latest()->first();

        $lead = [
            'id' => $customer->id,
            'name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'source' => $opportunity?->source ?? 'Direct',
            'status' => $this->mapStageToStatus($opportunity?->stage),
            'value' => $opportunity?->value ? (float) $opportunity->value : null,
            'assigned_to' => $opportunity?->assignee?->name,
            'created_at' => $customer->created_at->format('Y-m-d H:i:s'),
            'opportunity_id' => $opportunity?->id,
        ];

        return response()->json($lead);
    }

    /**
     * Update the specified lead.
     */
    public function update(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:customers,email,' . $customer->id,
            'phone' => 'sometimes|string|unique:customers,phone,' . $customer->id,
            'source' => 'nullable|string|max:255',
            'status' => 'nullable|in:hot,warm,cold,converted',
            'value' => 'nullable|numeric|min:0',
            'assigned_to' => 'nullable|exists:users,id',
            // 'project_id' => 'nullable|exists:projects,id', // Removed - leads are independent for now
        ]);

        $result = DB::transaction(function () use ($customer, $validated) {
            // Update customer if name/email/phone changed
            if (isset($validated['name']) || isset($validated['email']) || isset($validated['phone'])) {
                $customerData = [];
                
                if (isset($validated['name'])) {
                    $nameParts = explode(' ', $validated['name'], 2);
                    $customerData['first_name'] = $nameParts[0] ?? null;
                    $customerData['last_name'] = $nameParts[1] ?? null;
                }
                
                if (isset($validated['email'])) {
                    $customerData['email'] = $validated['email'];
                }
                
                if (isset($validated['phone'])) {
                    $customerData['phone'] = $validated['phone'];
                }
                
                $customer->update($customerData);
            }

            // Get or create opportunity
            $opportunity = $customer->opportunities()->open()->latest()->first();
            
            if (!$opportunity) {
                // Create new opportunity if none exists
                $opportunity = Opportunity::create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'name' => $customer->full_name,
                    'stage' => 'prospecting',
                    'created_by' => auth()->id(),
                ]);
            }

            // Map status to stage
            if (isset($validated['status'])) {
                $opportunity->stage = match($validated['status']) {
                    'hot' => 'prospecting',
                    'warm' => 'proposal',
                    'cold' => 'on_hold',
                    'converted' => 'closed_won',
                    default => 'prospecting',
                };
            }

            // Update opportunity fields
            if (isset($validated['value'])) {
                $opportunity->value = $validated['value'];
            }
            
            if (isset($validated['source'])) {
                $opportunity->source = $validated['source'];
            }
            
            // Project integration will be handled separately later
            // if (isset($validated['project_id'])) {
            //     $opportunity->project_id = $validated['project_id'];
            // }
            
            if (isset($validated['assigned_to'])) {
                $opportunity->assigned_to = $validated['assigned_to'];
            }

            $opportunity->calculateWeightedValue();
            $opportunity->save();

            return [
                'customer' => $customer->fresh(),
                'opportunity' => $opportunity->fresh(['assignee']),
            ];
        });

        // Format response
        $lead = [
            'id' => $result['customer']->id,
            'name' => $result['customer']->full_name,
            'email' => $result['customer']->email,
            'phone' => $result['customer']->phone,
            'source' => $result['opportunity']->source ?? 'Direct',
            'status' => $this->mapStageToStatus($result['opportunity']->stage),
            'value' => $result['opportunity']->value ? (float) $result['opportunity']->value : null,
            'assigned_to' => $result['opportunity']->assignee?->name,
            'created_at' => $result['customer']->created_at->format('Y-m-d H:i:s'),
            'opportunity_id' => $result['opportunity']->id,
        ];

        return response()->json($lead);
    }

    /**
     * Remove the specified lead.
     */
    public function destroy(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $customer->delete();

        return response()->json(['message' => 'Lead deleted successfully'], 204);
    }
}
