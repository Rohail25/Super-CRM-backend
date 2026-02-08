<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\SignupRequest;
use App\Services\SignupApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

class SignupRequestController extends Controller
{
    use HandlesApiErrors;
    public function __construct(
        private SignupApprovalService $approvalService
    ) {}

    /**
     * Display a listing of signup requests.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Only super admin can see all requests
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can view signup requests');
        }

        $query = SignupRequest::with('company', 'reviewer');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($requests);
    }

    /**
     * Store a newly created signup request.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_data' => 'required|array',
            'company_data.name' => 'required|string|max:255',
            'company_data.vat' => 'nullable|string|unique:companies,vat',
            'company_data.address' => 'nullable|string',
            'contact_person' => 'required|array',
            'contact_person.name' => 'required|string|max:255',
            'contact_person.email' => 'required|email|unique:users,email',
            'contact_person.password' => 'required|string|min:8',
            'requested_projects' => 'nullable|array',
            'requested_projects.*' => 'exists:projects,id',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                // Create company first
                $company = \App\Models\Company::create([
                    'name' => $validated['company_data']['name'],
                    'vat' => $validated['company_data']['vat'] ?? null,
                    'address' => $validated['company_data']['address'] ?? null,
                    'status' => 'pending',
                ]);

                // Create user
                $plainPassword = $validated['contact_person']['password'];
                
                $user = \App\Models\User::create([
                    'company_id' => $company->id,
                    'name' => $validated['contact_person']['name'],
                    'email' => $validated['contact_person']['email'],
                    'password' => $plainPassword,
                    'role' => 'company_admin',
                    'status' => 'pending',
                ]);

                // Store plain password (encrypted) for external API use
                $user->setPlainPassword($plainPassword);
                $user->save();

                // Create signup request
                $signupRequest = SignupRequest::create([
                    'company_id' => $company->id,
                    'requested_projects' => $validated['requested_projects'] ?? [],
                    'company_data' => $validated['company_data'],
                    'contact_person' => $validated['contact_person'],
                    'status' => 'pending',
                ]);

                return response()->json($signupRequest->load('company'), 201);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle database integrity constraint violations
            $errorCode = $e->getCode();
            
            if ($errorCode === '23000') {
                // Integrity constraint violation
                $errorMessage = $e->getMessage();
                
                if (str_contains($errorMessage, 'companies_vat_unique')) {
                    return response()->json([
                        'message' => 'Registration failed',
                        'errors' => [
                            'company_data.vat' => ['This VAT number is already registered. Please use a different VAT number or contact support if you believe this is an error.']
                        ]
                    ], 422);
                }
                
                if (str_contains($errorMessage, 'users_email_unique')) {
                    return response()->json([
                        'message' => 'Registration failed',
                        'errors' => [
                            'contact_person.email' => ['This email address is already registered. Please use a different email or try logging in instead.']
                        ]
                    ], 422);
                }
            }
            
            // Generic database error
            $dbError = $this->handleDatabaseException($e);
            if ($dbError) {
                return $dbError;
            }
            
            return $this->errorResponse(
                'Registration failed due to a database error. Please try again or contact support if the problem persists.',
                $e,
                500
            );
        } catch (\Exception $e) {
            // Handle any other exceptions
            $dbError = $this->handleDatabaseException($e);
            if ($dbError) {
                return $dbError;
            }
            
            return $this->errorResponse(
                'Registration failed. Please try again or contact support if the problem persists.',
                $e,
                500
            );
        }
    }

    /**
     * Approve a signup request.
     */
    public function approve(Request $request, SignupRequest $signupRequest)
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can approve signup requests');
        }

        $selectedProjects = $request->input('selected_projects', $signupRequest->requested_projects);

        $result = $this->approvalService->approveSignupRequest($signupRequest, $user, $selectedProjects);

        return response()->json([
            'message' => 'Signup request approved. Company must complete subscription to activate account.',
            'result' => $result,
            'subscription_required' => true,
        ]);
    }

    /**
     * Reject a signup request.
     */
    public function reject(Request $request, SignupRequest $signupRequest)
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can reject signup requests');
        }

        $validated = $request->validate([
            'rejection_reason' => 'nullable|string',
        ]);

        $signupRequest->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);

        return response()->json(['message' => 'Signup request rejected']);
    }
}
