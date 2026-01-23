<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyProjectAccess;
use App\Models\CompanyProjectUser;
use App\Models\Project;
use App\Models\SignupRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SignupApprovalService
{
    public function __construct(
        private ProjectIntegrationService $integrationService
    ) {}

    public function approveSignupRequest(SignupRequest $request, User $approver, ?array $selectedProjects = null): array
    {
        $results = [
            'succeeded' => [],
            'failed' => [],
            'partial' => false,
        ];

        DB::transaction(function () use ($request, $approver, $selectedProjects, &$results) {
            $company = $request->company;
            // Set status to 'approved' instead of 'active' - subscription required
            $company->status = 'approved';
            $company->subscription_status = 'approved';
            $company->save();

            // Don't activate the admin user yet - wait for subscription
            // User will be activated when subscription is completed via webhook

            $projects = $selectedProjects ?? $request->requested_projects;

            foreach ($projects as $projectId) {
                $project = Project::find($projectId);
                if (!$project) {
                    continue;
                }

                $access = CompanyProjectAccess::create([
                    'company_id' => $company->id,
                    'project_id' => $project->id,
                    'status' => 'pending',
                    'signup_request_data' => $request->company_data,
                ]);

                try {
                    $this->orchestrateProjectSignup($access, $project, $request);
                    $results['succeeded'][] = $project->name;

                } catch (\Exception $e) {
                    $access->status = 'partial_failed';
                    $access->last_error = $e->getMessage();
                    $access->retry_count = 0;
                    $access->save();

                    $results['failed'][] = [
                        'project' => $project->name,
                        'error' => $e->getMessage(),
                    ];
                    $results['partial'] = true;

                    Log::error('Project signup failed', [
                        'project_id' => $project->id,
                        'company_id' => $company->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update signup request
            $request->status = $results['partial'] ? 'partial_approved' : 'approved';
            $request->reviewed_by = $approver->id;
            $request->reviewed_at = now();
            $request->api_calls_log = $results;
            $request->save();
        });

        // Send subscription required notification to company admin
        $adminUser = $request->company->users()->where('role', 'company_admin')->first();
        if ($adminUser) {
            $adminUser->notify(new \App\Notifications\SubscriptionRequiredNotification($request->company));
        }

        // Queue retry job for failed projects
        if ($results['partial']) {
            \App\Jobs\RetryFailedProjectSignupJob::dispatch($request->id);
        }

        return $results;
    }

    public function orchestrateProjectSignup(
        CompanyProjectAccess $access,
        Project $project,
        SignupRequest $request
    ): void {
        $driver = $this->integrationService->getDriver($project);

        try {
            $result = $driver->signup($project, $request->company_data, $request->contact_person);

            // Store result (no passwords)
            $access->external_company_id = $result['external_company_id'] ?? null;
            if (isset($result['api_key']) || isset($result['api_secret'])) {
                $apiCreds = [];
                if (isset($result['api_key'])) {
                    $apiCreds['api_key'] = $result['api_key'];
                }
                if (isset($result['api_secret'])) {
                    $apiCreds['api_secret'] = $result['api_secret'];
                }
                $access->setEncryptedApiCredentials($apiCreds);
            }
            $access->status = 'active';
            $access->approved_at = now();
            $access->approved_by = auth()->id();
            $access->save();

            // Create user mapping
            if (isset($result['external_user_id'])) {
                CompanyProjectUser::create([
                    'company_project_access_id' => $access->id,
                    'user_id' => $request->company->users()->where('role', 'company_admin')->first()->id,
                    'external_user_id' => $result['external_user_id'],
                    'external_username' => $result['external_username'] ?? $request->contact_person['email'],
                    'status' => 'active',
                ]);
            }

        } catch (\Exception $e) {
            $access->last_error = $e->getMessage();
            $access->status = 'partial_failed';
            $access->save();
            throw $e;
        }
    }
}
