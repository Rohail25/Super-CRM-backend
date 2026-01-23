<?php

namespace App\Services;

use App\Models\CompanyProjectAccess;
use App\Models\CompanyProjectUser;
use App\Models\Project;
use App\Models\SSOTokenUsage;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SSOService
{
    private const JWT_ALGORITHM = 'HS256';
    private const JWT_ISSUER = 'leo24_crm';

    /**
     * Generate JWT SSO token with standard claims
     */
    public function generateJWTToken(
        CompanyProjectAccess $access,
        Project $project,
        User $user,
        ?CompanyProjectUser $projectUser = null
    ): string {
        $now = now();
        $expiresAt = $now->copy()->addSeconds($project->sso_token_expiry);

        // Generate unique JWT ID (jti) for replay protection
        $jti = Str::uuid()->toString();

        // Get external user ID if mapped
        $externalUserId = $projectUser?->external_user_id ?? $access->external_company_id;

        // Standard JWT claims
        $payload = [
            // Standard claims (RFC 7519)
            'iss' => self::JWT_ISSUER, // Issuer
            'aud' => $project->slug, // Audience (project)
            'sub' => (string) $user->id, // Subject (LEO24 user ID)
            'exp' => $expiresAt->timestamp, // Expiration
            'iat' => $now->timestamp, // Issued at
            'jti' => $jti, // JWT ID (for replay protection)

            // Custom claims
            'cid' => (string) $access->company_id, // Company ID
            'pid' => (string) $project->id, // Project ID
            'cpa_id' => (string) $access->id, // Company-Project-Access ID
            'ext_uid' => $externalUserId, // External user ID in target system
            'ext_cid' => $access->external_company_id, // External company ID
        ];

        // Sign with project's secret
        $secret = \Illuminate\Support\Facades\Crypt::decryptString($project->api_secret);
        $token = JWT::encode($payload, $secret, self::JWT_ALGORITHM);

        // Store token usage record for replay protection
        SSOTokenUsage::create([
            'jti' => $jti,
            'company_project_access_id' => $access->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'status' => 'issued',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $token;
    }

    /**
     * Get SSO URL for iframe projects (TOP-LEVEL redirect pattern)
     */
    public function getSSORedirectUrl(
        CompanyProjectAccess $access,
        Project $project,
        User $user
    ): string {
        // Get or create user mapping
        $projectUser = CompanyProjectUser::firstOrCreate(
            [
                'company_project_access_id' => $access->id,
                'user_id' => $user->id,
            ],
            [
                'status' => 'active',
            ]
        );

        // Generate JWT token
        $token = $this->generateJWTToken($access, $project, $user, $projectUser);

        // Build SSO redirect URL
        $ssoUrl = $project->sso_redirect_url ?? $project->admin_panel_url;
        $callbackUrl = $project->sso_callback_url ?? route('projects.iframe-callback', $project);

        // Add token and callback
        $separator = strpos($ssoUrl, '?') !== false ? '&' : '?';
        $ssoUrl .= $separator . 'token=' . urlencode($token);
        $ssoUrl .= '&callback=' . urlencode($callbackUrl);

        return $ssoUrl;
    }

    /**
     * Validate JWT token (for projects calling back to verify)
     */
    public function validateJWTToken(string $token, Project $project): array
    {
        try {
            $secret = \Illuminate\Support\Facades\Crypt::decryptString($project->api_secret);
            $decoded = JWT::decode($token, new Key($secret, self::JWT_ALGORITHM));

            // Check replay protection
            $tokenUsage = SSOTokenUsage::where('jti', $decoded->jti)->first();

            if (!$tokenUsage) {
                throw new \Exception('Token not found in usage log');
            }

            if ($tokenUsage->status === 'used') {
                throw new \Exception('Token already used (replay attack)');
            }

            if ($tokenUsage->status === 'revoked') {
                throw new \Exception('Token revoked');
            }

            // Mark as used
            $tokenUsage->update([
                'status' => 'used',
                'used_at' => now(),
            ]);

            return (array) $decoded;

        } catch (\Exception $e) {
            Log::error('JWT validation failed', [
                'error' => $e->getMessage(),
                'project_id' => $project->id,
            ]);
            throw $e;
        }
    }
}
