<?php

namespace App\Jobs;

use App\Models\CompanyProjectAccess;
use App\Models\SignupRequest;
use App\Services\SignupApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryFailedProjectSignupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $signupRequestId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SignupApprovalService $approvalService): void
    {
        $accesses = CompanyProjectAccess::where('status', 'partial_failed')
            ->where('retry_count', '<', 3)
            ->get();

        foreach ($accesses as $access) {
            try {
                $project = $access->project;
                $signupRequest = SignupRequest::where('company_id', $access->company_id)->first();

                if (!$signupRequest) {
                    continue;
                }

                // Retry the signup - create a new access if needed
                if ($access->status === 'partial_failed') {
                    $approvalService->orchestrateProjectSignup($access, $project, $signupRequest);
                }

                $access->status = 'active';
                $access->retry_count = 0;
                $access->last_error = null;
                $access->save();

            } catch (\Exception $e) {
                $access->retry_count++;
                $access->last_error = $e->getMessage();
                $access->save();

                if ($access->retry_count >= 3) {
                    Log::warning('Project signup failed after 3 retries', [
                        'access_id' => $access->id,
                        'project_id' => $access->project_id,
                    ]);
                }
            }
        }
    }
}
