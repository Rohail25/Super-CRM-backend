<?php

namespace App\Integrations\Drivers;

use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenericDriver implements ProjectIntegrationDriver
{
    public function signup(Project $project, array $companyData, array $userData): array
    {
        if (!$project->api_base_url || !$project->api_signup_endpoint) {
            throw new \Exception('Project API configuration is incomplete');
        }

        $client = Http::timeout(30);

        // Add authentication headers
        $headers = $this->getAuthHeaders($project);
        foreach ($headers as $key => $value) {
            $client->withHeader($key, $value);
        }

        $payload = [
            'company' => $companyData,
            'user' => $userData,
            'source' => 'leo24_crm',
        ];

        try {
            $response = $client->post(
                $project->api_base_url . $project->api_signup_endpoint,
                $payload
            );

            if (!$response->successful()) {
                throw new \Exception('API request failed: ' . $response->body());
            }

            $data = $response->json();

            return [
                'external_company_id' => $data['company_id'] ?? $data['organization_id'] ?? null,
                'external_user_id' => $data['user_id'] ?? $data['admin_id'] ?? null,
                'external_username' => $data['username'] ?? $userData['email'],
                'api_key' => $data['api_key'] ?? null,
                'api_secret' => $data['api_secret'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Generic driver signup failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getSSOUrl(Project $project, CompanyProjectAccess $access, User $user): string
    {
        // This will be handled by SSOService
        return $project->sso_redirect_url ?? $project->admin_panel_url ?? '';
    }

    public function sync(Project $project, CompanyProjectAccess $access): array
    {
        // Implement sync logic
        return [];
    }

    public function revoke(Project $project, CompanyProjectAccess $access): bool
    {
        // Implement revoke logic
        return true;
    }

    public function testConnection(Project $project): bool
    {
        try {
            $response = Http::timeout(5)->get($project->api_base_url);
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getAuthHeaders(Project $project): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($project->api_auth_type === 'bearer' && $project->api_key) {
            $headers['Authorization'] = 'Bearer ' . \Illuminate\Support\Facades\Crypt::decryptString($project->api_key);
        } elseif ($project->api_auth_type === 'basic' && $project->api_key && $project->api_secret) {
            $headers['Authorization'] = 'Basic ' . base64_encode(
                \Illuminate\Support\Facades\Crypt::decryptString($project->api_key) . ':' . \Illuminate\Support\Facades\Crypt::decryptString($project->api_secret)
            );
        }

        return $headers;
    }
}
