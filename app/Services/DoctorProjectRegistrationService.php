<?php

namespace App\Services;

use App\Models\CompanyProjectAccess;
use App\Models\CompanyProjectUser;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DoctorProjectRegistrationService
{
    private string $registerEndpoint = 'https://mydoctoradmin.mydoctorplus.it/api/auth/register';

    /**
     * Register users to doctor project when company gets access.
     */
    public function registerUsersForDoctorProject(CompanyProjectAccess $access): array
    {
        Log::info('DoctorProjectRegistrationService: Starting registration process', [
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
            Log::error('DoctorProjectRegistrationService: Project not found', [
                'access_id' => $access->id,
                'project_id' => $access->project_id,
            ]);
            return [
                'success' => false,
                'message' => 'Project not found',
            ];
        }
        
        Log::info('DoctorProjectRegistrationService: Project loaded', [
            'project_id' => $project->id,
            'project_slug' => $project->slug,
            'project_name' => $project->name,
        ]);
        
        // Check if this is the doctor project (mydoctor)
        if ($project->slug !== 'mydoctor') {
            Log::info('Skipping registration - not doctor project', [
                'project_slug' => $project->slug,
                'access_id' => $access->id,
            ]);
            return [
                'success' => false,
                'message' => 'Not a doctor project',
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

        // Get all users from company_project_users for this access
        $projectUsers = CompanyProjectUser::where('company_project_access_id', $access->id)
            ->where('status', 'active')
            ->with('user')
            ->get();

        Log::info('DoctorProjectRegistrationService: Found project users', [
            'access_id' => $access->id,
            'project_users_count' => $projectUsers->count(),
            'project_user_ids' => $projectUsers->pluck('user_id')->toArray(),
        ]);

        if ($projectUsers->isEmpty()) {
            Log::warning('No users found for doctor project registration', [
                'access_id' => $access->id,
                'company_id' => $access->company_id,
            ]);
            return [
                'success' => false,
                'message' => 'No users found for registration',
            ];
        }

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
                $registrationResult = $this->registerUser($user, $access);
                
                if ($registrationResult['success']) {
                    $results['success'][] = [
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'external_user_id' => $registrationResult['external_user_id'] ?? null,
                    ];
                    
                    // Update external_user_id if provided in response
                    if (isset($registrationResult['external_user_id']) && $registrationResult['external_user_id']) {
                        $projectUser->external_user_id = $registrationResult['external_user_id'];
                        $projectUser->save();
                        
                        Log::info('Updated external_user_id for project user', [
                            'project_user_id' => $projectUser->id,
                            'user_id' => $user->id,
                            'external_user_id' => $registrationResult['external_user_id'],
                        ]);
                    }
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
                Log::error('Failed to register user to doctor project', [
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
     * Register a single user to the doctor project API.
     */
    private function registerUser(User $user, CompanyProjectAccess $access): array
    {
        try {
            // Get plain password (encrypted in database)
            $plainPassword = $this->getPlainPassword($user);
            
            if (!$plainPassword) {
                // Generate a temporary password if not stored
                $plainPassword = $this->generateTemporaryPassword();
                Log::warning('Using temporary password for user registration', [
                    'user_id' => $user->id,
                ]);
            }

            // Prepare payload - ensure all required fields are present and valid
            // Note: External API expects 'fullName' instead of 'name' and requires 'role'
            $payload = [
                'fullName' => trim($user->name ?? ''),
                'email' => trim($user->email ?? ''),
                'password' => $plainPassword,
                'confirm_password' => $plainPassword,
                'role' => 'DOCTOR', // Default role for doctor project registration
            ];

            // Validate required fields before sending
            if (empty($payload['fullName'])) {
                Log::error('User fullName is empty', ['user_id' => $user->id]);
                return [
                    'success' => false,
                    'error' => 'User fullName is required',
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

            Log::info('Registering user to doctor project', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            // Get API credentials from company_project_access if available
            $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
            $apiCredentials = $access->getDecryptedApiCredentials();
            
            if ($apiCredentials) {
                Log::info('Using API credentials for registration', [
                    'user_id' => $user->id,
                    'has_api_key' => isset($apiCredentials['api_key']),
                ]);
                
                // Add API credentials to headers if needed
                if (isset($apiCredentials['api_key'])) {
                    $headers['Authorization'] = 'Bearer ' . $apiCredentials['api_key'];
                }
            }

            Log::info('Sending registration request', [
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'endpoint' => $this->registerEndpoint,
                'has_api_credentials' => !empty($apiCredentials),
                'payload' => [
                    'fullName' => $payload['fullName'],
                    'email' => $payload['email'],
                    'role' => $payload['role'],
                    'has_password' => !empty($payload['password']),
                    'password_length' => strlen($payload['password']),
                ],
            ]);

            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->post($this->registerEndpoint, $payload);

            Log::info('Registration response received', [
                'user_id' => $user->id,
                'email' => $user->email,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Extract external user ID from response
                $externalUserId = null;
                if (isset($responseData['data']['user']['_id'])) {
                    $externalUserId = $responseData['data']['user']['_id'];
                } elseif (isset($responseData['user']['_id'])) {
                    $externalUserId = $responseData['user']['_id'];
                } elseif (isset($responseData['user_id'])) {
                    $externalUserId = $responseData['user_id'];
                } elseif (isset($responseData['id'])) {
                    $externalUserId = $responseData['id'];
                }
                
                Log::info('User successfully registered to doctor project', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'external_user_id' => $externalUserId,
                    'response_status' => $response->status(),
                ]);

                return [
                    'success' => true,
                    'external_user_id' => $externalUserId,
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
                
                // Handle validation errors object
                if (isset($responseBody['errors'])) {
                    $errorDetails = $responseBody['errors'];
                    if (is_array($responseBody['errors'])) {
                        $errorMessages = [];
                        foreach ($responseBody['errors'] as $field => $messages) {
                            if (is_array($messages)) {
                                $errorMessages[] = ucfirst($field) . ': ' . implode(', ', $messages);
                            } else {
                                $errorMessages[] = ucfirst($field) . ': ' . $messages;
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
                
                Log::error('Failed to register user to doctor project', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'error_details' => $errorDetails,
                    'response_body' => $responseBody,
                    'payload_sent' => [
                        'fullName' => $payload['fullName'],
                        'email' => $payload['email'],
                        'role' => $payload['role'],
                        'has_password' => !empty($payload['password']),
                        'password_length' => strlen($payload['password']),
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
            Log::error('Exception while registering user to doctor project', [
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

    /**
     * Get plain password for user (decrypted from storage).
     */
    private function getPlainPassword(User $user): ?string
    {
        return $user->getPlainPassword();
    }

    /**
     * Generate a temporary password for registration.
     */
    private function generateTemporaryPassword(): string
    {
        // Generate a secure random password
        return \Illuminate\Support\Str::random(12);
    }
}
