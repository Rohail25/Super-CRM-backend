<?php

namespace App\Integrations\Drivers;

use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Models\User;

interface ProjectIntegrationDriver
{
    /**
     * Signup a company/user in the target project
     */
    public function signup(Project $project, array $companyData, array $userData): array;

    /**
     * Generate SSO URL
     */
    public function getSSOUrl(Project $project, CompanyProjectAccess $access, User $user): string;

    /**
     * Sync data from project to LEO24
     */
    public function sync(Project $project, CompanyProjectAccess $access): array;

    /**
     * Revoke access
     */
    public function revoke(Project $project, CompanyProjectAccess $access): bool;

    /**
     * Test connection
     */
    public function testConnection(Project $project): bool;
}
