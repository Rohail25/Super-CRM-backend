<?php

namespace App\Jobs;

use App\Models\SignupRequest;
use App\Services\SignupApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSignupApprovalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $signupRequestId,
        public int $approverId,
        public ?array $selectedProjects = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SignupApprovalService $approvalService): void
    {
        $signupRequest = SignupRequest::findOrFail($this->signupRequestId);
        $approver = \App\Models\User::findOrFail($this->approverId);

        $approvalService->approveSignupRequest($signupRequest, $approver, $this->selectedProjects);
    }
}
