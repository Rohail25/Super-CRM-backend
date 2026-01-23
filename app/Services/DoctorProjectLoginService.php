<?php

namespace App\Services;

use App\Models\CompanyProjectAccess;
use App\Models\CompanyProjectUser;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DoctorProjectLoginService
{
    private string $loginEndpoint = 'https://mydoctoradmin.mydoctorplus.it/api/auth/login';
    private string $testDataEndpoint = 'https://mydoctoradmin.mydoctorplus.it/api/doctor-test/test-data';

    /**
     * Login user to doctor project and fetch test data.
     */
    public function loginAndFetchData(CompanyProjectAccess $access, User $user): array
    {
        $project = $access->project;
        
        // Check if this is the doctor project
        if ($project->slug !== 'mydoctor') {
            return [
                'success' => false,
                'message' => 'Not a doctor project',
            ];
        }

        // Get or create company project user
        $projectUser = CompanyProjectUser::firstOrCreate(
            [
                'company_project_access_id' => $access->id,
                'user_id' => $user->id,
            ],
            [
                'status' => 'active',
            ]
        );

        // Check if we have a valid token
        $token = $projectUser->external_token;
        $tokenExpiresAt = $projectUser->token_expires_at;

        // If token exists and is not expired, use it
        if ($token && $tokenExpiresAt && $tokenExpiresAt->isFuture()) {
            Log::info('Using existing token for doctor project', [
                'user_id' => $user->id,
                'access_id' => $access->id,
            ]);
            
            return $this->fetchTestData($token);
        }

        // Login to get new token
        $loginResult = $this->login($user);
        
        if (!$loginResult['success']) {
            return $loginResult;
        }

        $token = $loginResult['token'];
        $tokenData = $loginResult['token_data'] ?? null;

        // Store token in database
        $projectUser->external_token = $token;
        
        // Extract expiration from JWT if available
        if ($tokenData) {
            $exp = $tokenData['exp'] ?? null;
            if ($exp) {
                $projectUser->token_expires_at = \Carbon\Carbon::createFromTimestamp($exp);
            }
        } else {
            // Default to 7 days if we can't parse the token
            $projectUser->token_expires_at = now()->addDays(7);
        }
        
        $projectUser->external_user_id = $loginResult['user_id'] ?? null;
        $projectUser->save();

        // Fetch test data with the token
        return $this->fetchTestData($token);
    }

    /**
     * Login user to doctor project API.
     */
    private function login(User $user): array
    {
        try {
            $plainPassword = $user->getPlainPassword();
            
            if (!$plainPassword) {
                return [
                    'success' => false,
                    'message' => 'Password not available for external login',
                ];
            }

            $payload = [
                'email' => $user->email,
                'password' => $plainPassword,
            ];

            Log::info('Logging in user to doctor project', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            $response = Http::timeout(30)
                ->post($this->loginEndpoint, $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                
                if (isset($responseData['success']) && $responseData['success'] && isset($responseData['data']['token'])) {
                    $token = $responseData['data']['token'];
                    $userData = $responseData['data']['user'] ?? null;
                    
                    // Decode JWT to get expiration
                    $tokenData = $this->decodeJWT($token);
                    
                    Log::info('User successfully logged in to doctor project', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);

                    return [
                        'success' => true,
                        'token' => $token,
                        'token_data' => $tokenData,
                        'user_id' => $userData['_id'] ?? null,
                        'user_data' => $userData,
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Invalid response from login endpoint',
                    ];
                }
            } else {
                $errorMessage = $response->json()['message'] ?? $response->body() ?? 'Unknown error';
                
                Log::error('Failed to login user to doctor project', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'status_code' => $response->status(),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception while logging in user to doctor project', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch test data from doctor project API.
     */
    private function fetchTestData(string $token): array
    {
        try {
            Log::info('Fetching test data from doctor project', []);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])
                ->get($this->testDataEndpoint);

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::info('Successfully fetched test data from doctor project', []);

                return [
                    'success' => true,
                    'data' => $responseData['data'] ?? $responseData,
                    'message' => $responseData['message'] ?? 'Data retrieved successfully',
                ];
            } else {
                $errorMessage = $response->json()['message'] ?? $response->body() ?? 'Unknown error';
                
                Log::error('Failed to fetch test data from doctor project', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'status_code' => $response->status(),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception while fetching test data from doctor project', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Decode JWT token to get payload.
     */
    private function decodeJWT(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payload = $parts[1];
            $decoded = base64_decode(strtr($payload, '-_', '+/'));
            return json_decode($decoded, true);
        } catch (\Exception $e) {
            Log::warning('Failed to decode JWT token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
