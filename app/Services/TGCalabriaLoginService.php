<?php

namespace App\Services;

use App\Models\CompanyProjectAccess;
use App\Models\CompanyProjectUser;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TGCalabriaLoginService
{
    private string $loginEndpoint = 'https://api.tgcalabriareport.com/api/v1/auth/login';
    private string $statsEndpoint = 'https://api.tgcalabriareport.com/api/v1/crm/news/stats/user';
    private string $categoriesEndpoint = 'https://api.tgcalabriareport.com/api/v1/crm/categories';

    /**
     * Login user to TG Calabria project and fetch user stats.
     */
    public function loginAndFetchStats(CompanyProjectAccess $access, User $user): array
    {
        $project = $access->project;
        
        // Check if this is the TG Calabria project
        if ($project->slug !== 'tg-calabria') {
            return [
                'success' => false,
                'message' => 'Not a TG Calabria project',
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
            Log::info('Using existing token for TG Calabria project', [
                'user_id' => $user->id,
                'access_id' => $access->id,
            ]);
            
            return $this->fetchUserStats($token, $projectUser->external_user_id);
        }

        // Login to get new token
        $loginResult = $this->login($user);
        
        if (!$loginResult['success']) {
            return $loginResult;
        }

        $token = $loginResult['token'];
        $userId = $loginResult['user_id'] ?? null;
        $tokenData = $loginResult['token_data'] ?? null;

        // Store token in database
        $projectUser->external_token = $token;
        $projectUser->external_user_id = $userId;
        
        // Extract expiration from token if available
        if ($tokenData && isset($tokenData['exp'])) {
            $projectUser->token_expires_at = \Carbon\Carbon::createFromTimestamp($tokenData['exp']);
        } else {
            // Default to 7 days if we can't parse the token
            $projectUser->token_expires_at = now()->addDays(7);
        }
        
        $projectUser->save();

        // Fetch user stats with the token
        return $this->fetchUserStats($token, $userId);
    }

    /**
     * Login user to TG Calabria API.
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

            Log::info('Logging in user to TG Calabria project', [
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
                    
                    Log::info('User successfully logged in to TG Calabria project', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);

                    return [
                        'success' => true,
                        'token' => $token,
                        'token_data' => $tokenData,
                        'user_id' => $userData['id'] ?? null,
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
                
                Log::error('Failed to login user to TG Calabria project', [
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
            Log::error('Exception while logging in user to TG Calabria project', [
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
     * Fetch user stats from TG Calabria API.
     */
    private function fetchUserStats(string $token, ?string $userId): array
    {
        try {
            if (!$userId) {
                return [
                    'success' => false,
                    'message' => 'Missing user ID',
                ];
            }

            Log::info('Fetching user stats from TG Calabria project', [
                'user_id' => $userId,
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])
                ->get("{$this->statsEndpoint}/{$userId}");

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::info('Successfully fetched user stats from TG Calabria project', []);

                return [
                    'success' => true,
                    'data' => $responseData['data'] ?? $responseData,
                    'message' => $responseData['message'] ?? 'Stats retrieved successfully',
                    'token' => $token,
                ];
            } else {
                $errorMessage = $response->json()['message'] ?? $response->body() ?? 'Unknown error';
                
                Log::error('Failed to fetch user stats from TG Calabria project', [
                    'user_id' => $userId,
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
            Log::error('Exception while fetching user stats from TG Calabria project', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch categories from TG Calabria API.
     */
    public function fetchCategories(string $token): array
    {
        try {
            Log::info('Fetching categories from TG Calabria project', []);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])
                ->get($this->categoriesEndpoint);

            if ($response->successful()) {
                $responseData = $response->json();
                
                return [
                    'success' => true,
                    'data' => $responseData['data'] ?? $responseData,
                ];
            } else {
                $errorMessage = $response->json()['message'] ?? 'Failed to fetch categories';
                
                Log::error('Failed to fetch categories from TG Calabria project', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMessage,
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception while fetching categories from TG Calabria project', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create article in TG Calabria project.
     */
    public function createArticle(string $token, array $articleData): array
    {
        try {
            Log::info('Creating article in TG Calabria project', [
                'title' => $articleData['title'] ?? 'N/A',
            ]);

            $payload = [
                'title' => $articleData['title'] ?? '',
                'slug' => $articleData['slug'] ?? '',
                'summary' => $articleData['summary'] ?? '',
                'content' => $articleData['content'] ?? '',
                'categoryId' => $articleData['categoryId'] ?? '',
                'isFeatured' => $articleData['isFeatured'] ?? false,
                'status' => $articleData['status'] ?? 'PUBLISHED',
                'isBreaking' => $articleData['isBreaking'] ?? false,
                'tags' => $articleData['tags'] ?? [],
            ];

            // Only add mainImage if provided
            if (!empty($articleData['mainImage'])) {
                $payload['mainImage'] = $articleData['mainImage'];
            }

            $response = Http::timeout(60)
                ->withToken($token)
                ->post('https://api.tgcalabriareport.com/api/v1/crm/news', $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::info('Article created successfully in TG Calabria project', [
                    'title' => $articleData['title'] ?? 'N/A',
                ]);

                return [
                    'success' => true,
                    'data' => $responseData['data'] ?? $responseData,
                    'message' => $responseData['message'] ?? 'Article created successfully',
                ];
            } else {
                $errorMessage = $response->json()['message'] ?? $response->body() ?? 'Unknown error';
                
                Log::error('Failed to create article in TG Calabria project', [
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
            Log::error('Exception while creating article in TG Calabria project', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch all news for the user.
     */
    public function fetchNews(string $token): array
    {
        try {
            Log::info('Fetching news from TG Calabria project');

            $response = Http::timeout(30)
                ->withToken($token)
                ->get('https://api.tgcalabriareport.com/api/v1/crm/news');

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::info('News fetched successfully from TG Calabria project');

                return [
                    'success' => true,
                    'data' => $responseData['data'] ?? $responseData,
                ];
            } else {
                $errorMessage = $response->json()['message'] ?? $response->body() ?? 'Unknown error';
                
                Log::error('Failed to fetch news from TG Calabria project', [
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
            Log::error('Exception while fetching news from TG Calabria project', [
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
