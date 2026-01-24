<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Customer;
use App\Models\ActivityLog;
use App\Services\CustomerDeduplicationService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use HandlesApiErrors;
    public function __construct(
        private CustomerDeduplicationService $deduplicationService
    ) {}

    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Customer::query();

        // Scope by company (unless super admin)
        if (!$user->isSuperAdmin()) {
            $query->where('company_id', $user->company_id);
        } elseif ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Apply filters
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('vat', 'like', "%{$search}%");
            });
        }

        $customers = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($customers);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => 'required|email',
            'phone' => 'required|string',
            'vat' => 'nullable|string',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        // Use deduplication service
        $customer = $this->deduplicationService->findOrCreateCustomer($validated, $companyId);

        return response()->json($customer, 201);
    }

    /**
     * Display the specified customer with all CRM data.
     */
    public function show(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        // Load all related CRM data
        $customer->load([
            'opportunities.assignee',
            'tasks.assignee',
            'tasks.creator',
            'notes.user',
            'documents.user',
            'company'
        ]);

        // Get activity logs for this customer
        $activityLogs = ActivityLog::where('model_type', Customer::class)
            ->where('model_id', $customer->id)
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        // Calculate statistics
        $stats = [
            'opportunities_count' => $customer->opportunities()->count(),
            'open_opportunities_count' => $customer->opportunities()->open()->count(),
            'total_opportunities_value' => $customer->opportunities()->sum('value'),
            'tasks_count' => $customer->tasks()->count(),
            'pending_tasks_count' => $customer->tasks()->pending()->count(),
            'completed_tasks_count' => $customer->tasks()->completed()->count(),
            'notes_count' => $customer->notes()->count(),
            'documents_count' => $customer->documents()->count(),
            'activity_logs_count' => $activityLogs->count(),
        ];

        return response()->json([
            'customer' => $customer,
            'stats' => $stats,
            'activity_logs' => $activityLogs,
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated = $request->validate([
            'email' => 'sometimes|email|unique:customers,email,' . $customer->id,
            'phone' => 'sometimes|string|unique:customers,phone,' . $customer->id,
            'vat' => 'nullable|string|unique:customers,vat,' . $customer->id,
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        return response()->json($customer);
    }

    /**
     * Remove the specified customer.
     */
    public function destroy(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    /**
     * Merge duplicate customers.
     */
    public function merge(Request $request)
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array|min:2',
            'customer_ids.*' => 'exists:customers,id',
            'primary_customer_id' => 'required|exists:customers,id',
        ]);

        $customer = $this->deduplicationService->mergeCustomers(
            $validated['customer_ids'],
            $validated['primary_customer_id']
        );

        return response()->json([
            'message' => 'Customers merged successfully',
            'customer' => $customer,
        ]);
    }
}
