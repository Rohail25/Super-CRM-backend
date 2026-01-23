<?php

namespace App\Services;

use App\Models\CompanyProjectAccess;
use App\Models\CompanyProjectUser;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TGCalabriaProjectRegistrationService
{
    private string $loginEndpoint = 'https://api.tgcalabriareport.com/api/v1/auth/login';
    private string $registerEndpoint = 'https://api.tgcalabriareport.com/api/v1/crm/users/register';
    
    // Admin credentials for TG Calabria API
    private string $adminEmail = 'admin@gmail.com';
    private string $adminPassword = '11221122';

    /**
     * Register users to TG Calabria project when company gets access.
     */
    public function registerUsersForTGCalabriaProject(CompanyProjectAccess $access): array
    {
        Log::info('TGCalabriaProjectRegistrationService: Starting registration process', [
            'access_id' => $access->id,
            'company_id' => $access->company_id,
            'project_id' => $access->project_id,
        ]);

        // Load project relationship if not loaded
        if (!$access->relationLoaded('project')) {
            $access->load('project');
        }
        
        $project = $access->project;
        
        if (!$project) {
            Log::error('TGCalabriaProjectRegistrationService: Project not found', [
                'access_id' => $access->id,
                'project_id' => $access->project_id,
            ]);
            return [
                'success' => false,
                'message' => 'Project not found',
            ];
        }
        
        Log::info('TGCalabriaProjectRegistrationService: Project loaded', [
            'project_id' => $project->id,
            'project_slug' => $project->slug,
            'project_name' => $project->name,
        ]);
        
        // Check if this is the TG Calabria project
        if ($project->slug !== 'tg-calabria') {
            Log::info('Skipping registration - not TG Calabria project', [
                'project_slug' => $project->slug,
                'access_id' => $access->id,
            ]);
            return [
                'success' => false,
                'message' => 'Not a TG Calabria project',
            ];
        }

        // Only proceed if access is active
        if ($access->status !== 'active') {
            Log::info('Skipping registration - access not active', [
                'access_id' => $access->id,
                'status' => $access->status,
            ]);
            return [
                'success' => false,
                'message' => 'Project access is not active',
            ];
        }

        // Get admin token first
        $adminToken = $this->getAdminToken();
        if (!$adminToken) {
            Log::error('Failed to get admin token for TG Calabria registration', [
                'access_id' => $access->id,
            ]);
            return [
                'success' => false,
                'message' => 'Failed to authenticate with TG Calabria API',
            ];
        }

        // Get all users from company_project_users for this access
        $projectUsers = CompanyProjectUser::where('company_project_access_id', $access->id)
            ->where('status', 'active')
            ->with('user')
            ->get();

        Log::info('TGCalabriaProjectRegistrationService: Found project users', [
            'access_id' => $access->id,
            'project_users_count' => $projectUsers->count(),
            'project_user_ids' => $projectUsers->pluck('user_id')->toArray(),
        ]);

        if ($projectUsers->isEmpty()) {
            Log::warning('No users found for TG Calabria project registration', [
                'access_id' => $access->id,
                'company_id' => $access->company_id,
            ]);
            return [
                'success' => false,
                'message' => 'No users found for registration',
            ];
        }

        // Load company to get company name
        if (!$access->relationLoaded('company')) {
            $access->load('company');
        }
        $company = $access->company;
        $companyName = $company ? $company->name : '';

        $results = [
            'success' => [],
            'failed' => [],
            'total' => $projectUsers->count(),
        ];

        foreach ($projectUsers as $projectUser) {
            $user = $projectUser->user;
            
            if (!$user) {
                $results['failed'][] = [
                    'user_id' => $projectUser->user_id,
                    'error' => 'User not found',
                ];
                continue;
            }

            // Check if user has plain password
            $plainPassword = $user->getPlainPassword();
            if (!$plainPassword) {
                Log::warning('User does not have plain password stored, skipping registration', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
                $results['failed'][] = [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'error' => 'User password not available for external registration',
                ];
                continue;
            }

            try {
                $registrationResult = $this->registerUser($user, $adminToken, $companyName);
                
                if ($registrationResult['success']) {
                    $results['success'][] = [
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'external_user_id' => $registrationResult['external_user_id'] ?? null,
                    ];
                    
                    // Update company_project_user with external data
                    $projectUser->external_user_id = $registrationResult['external_user_id'] ?? null;
                    $projectUser->external_username = $registrationResult['external_username'] ?? null;
                    $projectUser->external_role = $registrationResult['external_role'] ?? 'EDITOR';
                    $projectUser->external_token = $registrationResult['external_token'] ?? null;
                    $projectUser->save();
                    
                    Log::info('Updated external data for project user', [
                        'project_user_id' => $projectUser->id,
                        'user_id' => $user->id,
                        'external_user_id' => $registrationResult['external_user_id'],
                        'external_username' => $registrationResult['external_username'],
                        'external_role' => $registrationResult['external_role'],
                    ]);
                } else {
                    $errorMessage = $registrationResult['error'] ?? $registrationResult['message'] ?? 'Unknown error';
                    $errorDetails = $registrationResult['error_details'] ?? null;
                    
                    $failedEntry = [
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'error' => $errorMessage,
                    ];
                    
                    if ($errorDetails) {
                        $failedEntry['error_details'] = $errorDetails;
                    }
                    
                    $results['failed'][] = $failedEntry;
                    
                    Log::warning('User registration failed', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'error' => $errorMessage,
                        'error_details' => $errorDetails,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to register user to TG Calabria project', [
                    'user_id' => $user->id,
                    'access_id' => $access->id,
                    'error' => $e->getMessage(),
                ]);
                
                $results['failed'][] = [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => count($results['success']) > 0,
            'results' => $results,
        ];
    }

    /**
     * Get admin token by logging in to TG Calabria API.
     */
    private function getAdminToken(): ?string
    {
        try {
            Log::info('Attempting to get admin token from TG Calabria API', [
                'endpoint' => $this->loginEndpoint,
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->loginEndpoint, [
                    'email' => $this->adminEmail,
                    'password' => $this->adminPassword,
                ]);

            Log::info('Admin login response received', [
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Extract token from response (check multiple possible locations)
                $token = $responseData['data']['token'] 
                    ?? $responseData['token'] 
                    ?? $responseData['data']['access_token'] 
                    ?? $responseData['access_token'] 
                    ?? null;
                
                if ($token) {
                    Log::info('Successfully obtained admin token', [
                        'token_length' => strlen($token),
                    ]);
                    return $token;
                } else {
                    Log::error('Token not found in login response', [
                        'response_data' => $responseData,
                    ]);
                }
            } else {
                Log::error('Failed to get admin token', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception while getting admin token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return null;
    }

    /**
     * Register a single user to the TG Calabria API.
     */
    private function registerUser(User $user, string $adminToken, string $companyName): array
    {
        try {
            // Get plain password (encrypted in database)
            $plainPassword = $user->getPlainPassword();
            
            if (!$plainPassword) {
                Log::error('User does not have plain password', [
                    'user_id' => $user->id,
                ]);
                return [
                    'success' => false,
                    'error' => 'User password not available',
                ];
            }

            // Prepare payload according to API documentation
            $payload = [
                'email' => trim($user->email ?? ''),
                'password' => $plainPassword,
                'name' => trim($user->name ?? ''),
                'role' => 'EDITOR', // Default role as specified
                'companyName' => trim($companyName ?? ''),
            ];

            // Validate required fields before sending
            if (empty($payload['name'])) {
                Log::error('User name is empty', ['user_id' => $user->id]);
                return [
                    'success' => false,
                    'error' => 'User name is required',
                ];
            }

            if (empty($payload['email']) || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
                Log::error('User email is invalid', ['user_id' => $user->id, 'email' => $payload['email']]);
                return [
                    'success' => false,
                    'error' => 'Valid email is required',
                ];
            }

            if (empty($payload['password']) || strlen($payload['password']) < 6) {
                Log::error('Password is too short', ['user_id' => $user->id]);
                return [
                    'success' => false,
                    'error' => 'Password must be at least 6 characters',
                ];
            }

            Log::info('Registering user to TG Calabria project', [
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $adminToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->registerEndpoint, $payload);

            Log::info('Registration response received', [
                'user_id' => $user->id,
                'email' => $user->email,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Extract external user data from response
                $externalUserId = null;
                $externalUsername = null;
                $externalRole = 'EDITOR';
                $externalToken = null;
                
                // Check response structure based on API documentation
                if (isset($responseData['data'])) {
                    $data = $responseData['data'];
                    $externalUserId = $data['id'] ?? null;
                    $externalUsername = $data['email'] ?? $data['name'] ?? null;
                    $externalRole = $data['role'] ?? 'EDITOR';
                } elseif (isset($responseData['id'])) {
                    $externalUserId = $responseData['id'];
                    $externalUsername = $responseData['email'] ?? $responseData['name'] ?? null;
                    $externalRole = $responseData['role'] ?? 'EDITOR';
                }
                
                Log::info('User successfully registered to TG Calabria project', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'external_user_id' => $externalUserId,
                    'external_username' => $externalUsername,
                    'external_role' => $externalRole,
                    'response_status' => $response->status(),
                ]);

                return [
                    'success' => true,
                    'external_user_id' => $externalUserId,
                    'external_username' => $externalUsername,
                    'external_role' => $externalRole,
                    'external_token' => $externalToken, // Token is not returned in registration, only in login
                    'message' => 'User registered successfully',
                ];
            } else {
                $responseBody = $response->json();
                
                // Extract detailed error message
                $errorMessage = 'Unknown error';
                $errorDetails = [];
                
                // Try multiple ways to extract error message
                if (isset($responseBody['message'])) {
                    $errorMessage = $responseBody['message'];
                } elseif (isset($responseBody['error'])) {
                    if (is_string($responseBody['error'])) {
                        $errorMessage = $responseBody['error'];
                    } elseif (is_array($responseBody['error'])) {
                        $errorMessage = json_encode($responseBody['error']);
                    } else {
                        $errorMessage = 'Validation error';
                    }
                }
                
                // Handle validation errors array
                if (isset($responseBody['errors'])) {
                    $errorDetails = $responseBody['errors'];
                    if (is_array($responseBody['errors'])) {
                        $errorMessages = [];
                        foreach ($responseBody['errors'] as $error) {
                            if (is_array($error) && isset($error['field']) && isset($error['message'])) {
                                $errorMessages[] = ucfirst($error['field']) . ': ' . $error['message'];
                            } elseif (is_string($error)) {
                                $errorMessages[] = $error;
                            }
                        }
                        if (!empty($errorMessages)) {
                            $errorMessage = implode('; ', $errorMessages);
                        } else {
                            $errorMessage = 'Validation error: ' . json_encode($responseBody['errors']);
                        }
                    } else {
                        $errorMessage = 'Validation error: ' . $responseBody['errors'];
                    }
                }
                
                // Check for data.message (nested error)
                if (isset($responseBody['data']['message'])) {
                    $errorMessage = $responseBody['data']['message'];
                }
                
                // If still generic, use response body
                if ($errorMessage === 'Unknown error' && $response->body()) {
                    $errorMessage = $response->body();
                }
                
                Log::error('Failed to register user to TG Calabria project', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'error_details' => $errorDetails,
                    'response_body' => $responseBody,
                    'payload_sent' => [
                        'email' => $payload['email'],
                        'name' => $payload['name'],
                        'role' => $payload['role'],
                        'has_password' => !empty($payload['password']),
                        'password_length' => strlen($payload['password']),
                        'companyName' => $payload['companyName'],
                    ],
                ]);

                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'error_details' => $errorDetails,
                    'status_code' => $response->status(),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception while registering user to TG Calabria project', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
