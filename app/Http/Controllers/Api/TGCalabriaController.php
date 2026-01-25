<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\CompanyProjectUser;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TGCalabriaController extends Controller
{
    use HandlesApiErrors;
    private string $baseUrl = 'https://api.tgcalabriareport.com/api/v1';
    private string $loginEndpoint;
    private string $categoriesEndpoint;
    private string $newsEndpoint;
    private string $newsStatsEndpoint;

    public function __construct()
    {
        $this->loginEndpoint = $this->baseUrl . '/auth/login';
        $this->categoriesEndpoint = $this->baseUrl . '/crm/categories';
        $this->newsEndpoint = $this->baseUrl . '/crm/news';
        $this->newsStatsEndpoint = $this->baseUrl . '/crm/news/stats/user';
    }

    /**
     * Login user to TG Calabria API and store token.
     */
    public function login(Request $request, int $projectId)
    {
        $user = $request->user();

        // Verify project is TG Calabria
        $project = Project::findOrFail($projectId);
        if ($project->slug !== 'tg-calabria') {
            return response()->json([
                'message' => 'This endpoint is only for TG Calabria project',
            ], 400);
        }

        // Check if user has access to this project
        $projectUser = CompanyProjectUser::where('user_id', $user->id)
            ->whereHas('companyProjectAccess', function ($query) use ($projectId) {
                $query->where('project_id', $projectId)
                    ->where('status', 'active');
            })
            ->first();

        if (!$projectUser) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }

        // Get user's plain password
        $plainPassword = $user->getPlainPassword();
        if (!$plainPassword) {
            return response()->json([
                'message' => 'User password not available for external login',
            ], 400);
        }

        try {
            // Login to TG Calabria API
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->loginEndpoint, [
                    'email' => $user->email,
                    'password' => $plainPassword,
                ]);

            Log::info('TG Calabria login response', [
                'user_id' => $user->id,
                'email' => $user->email,
                'status_code' => $response->status(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Extract token from response
                $token = $responseData['data']['token'] 
                    ?? $responseData['token'] 
                    ?? $responseData['data']['access_token'] 
                    ?? $responseData['access_token'] 
                    ?? null;
                
                // Extract user ID from response
                $externalUserId = null;
                if (isset($responseData['data']['user']['id'])) {
                    $externalUserId = $responseData['data']['user']['id'];
                } elseif (isset($responseData['user']['id'])) {
                    $externalUserId = $responseData['user']['id'];
                }
                
                if ($token) {
                    // Store token and external_user_id in company_project_users
                    $projectUser->external_token = $token;
                    if ($externalUserId) {
                        $projectUser->external_user_id = $externalUserId;
                    }
                    $projectUser->save();

                    Log::info('Token stored successfully', [
                        'user_id' => $user->id,
                        'project_user_id' => $projectUser->id,
                        'external_user_id' => $externalUserId,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Login successful',
                        'token' => $token,
                        'external_user_id' => $externalUserId,
                    ]);
                } else {
                    Log::error('Token not found in login response', [
                        'user_id' => $user->id,
                        'response_data' => $responseData,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Token not found in response',
                    ], 500);
                }
            } else {
                $responseBody = $response->json();
                $errorMessage = $responseBody['message'] ?? 'Login failed';
                
                Log::error('TG Calabria login failed', [
                    'user_id' => $user->id,
                    'status_code' => $response->status(),
                    'error' => $errorMessage,
                    'response_body' => $responseBody,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], $response->status());
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to login to TG Calabria',
                $e,
                500,
                ['user_id' => $user->id, 'project_id' => $projectId]
            );
        }
    }

    /**
     * Get categories from TG Calabria API.
     */
    public function getCategories(Request $request, int $projectId)
    {
        $user = $request->user();

        // Verify project is TG Calabria
        $project = Project::findOrFail($projectId);
        if ($project->slug !== 'tg-calabria') {
            return response()->json([
                'message' => 'This endpoint is only for TG Calabria project',
            ], 400);
        }

        // Get user's token from company_project_users
        $projectUser = CompanyProjectUser::where('user_id', $user->id)
            ->whereHas('companyProjectAccess', function ($query) use ($projectId) {
                $query->where('project_id', $projectId)
                    ->where('status', 'active');
            })
            ->first();

        if (!$projectUser) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }

        if (!$projectUser->external_token) {
            return response()->json([
                'message' => 'Please login first to get authentication token',
            ], 401);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $projectUser->external_token,
                    'Accept' => 'application/json',
                ])
                ->get($this->categoriesEndpoint);

            Log::info('TG Calabria categories response', [
                'user_id' => $user->id,
                'status_code' => $response->status(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return response()->json($responseData);
            } else {
                $responseBody = $response->json();
                $errorMessage = $responseBody['message'] ?? 'Failed to fetch categories';
                
                Log::error('TG Calabria categories failed', [
                    'user_id' => $user->id,
                    'status_code' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], $response->status());
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to fetch TG Calabria categories',
                $e,
                500,
                ['user_id' => $user->id, 'project_id' => $projectId]
            );
        }
    }

    /**
     * Create article in TG Calabria API.
     */
    public function createArticle(Request $request, int $projectId)
    {
        $user = $request->user();

        // Verify project is TG Calabria
        $project = Project::findOrFail($projectId);
        if ($project->slug !== 'tg-calabria') {
            return response()->json([
                'message' => 'This endpoint is only for TG Calabria project',
            ], 400);
        }

        // Get user's token from company_project_users
        $projectUser = CompanyProjectUser::where('user_id', $user->id)
            ->whereHas('companyProjectAccess', function ($query) use ($projectId) {
                $query->where('project_id', $projectId)
                    ->where('status', 'active');
            })
            ->first();

        if (!$projectUser) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }

        if (!$projectUser->external_token) {
            return response()->json([
                'message' => 'Please login first to get authentication token',
            ], 401);
        }

        // Preprocess FormData: convert string booleans and parse tags array
        $requestData = $request->all();
        
        // Convert string booleans to actual booleans
        if (isset($requestData['isFeatured'])) {
            $requestData['isFeatured'] = filter_var($requestData['isFeatured'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($requestData['isBreaking'])) {
            $requestData['isBreaking'] = filter_var($requestData['isBreaking'], FILTER_VALIDATE_BOOLEAN);
        }
        
        // Handle tags array from FormData (tags[] format)
        if ($request->has('tags') && is_array($request->input('tags'))) {
            $requestData['tags'] = $request->input('tags');
        }
        
        // Merge processed data back into request
        $request->merge($requestData);

        // Validate request
        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'slug' => 'nullable|string|max:500',
            'summary' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'categoryId' => 'required|string',
            'status' => 'nullable|in:DRAFT,PUBLISHED',
            'isFeatured' => 'nullable|boolean',
            'isBreaking' => 'nullable|boolean',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'mainImage' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
        ]);

        try {
            // Handle image upload
            $imageUrl = null;
            if ($request->hasFile('mainImage')) {
                $image = $request->file('mainImage');
                $path = $image->store('tg-calabria/articles/' . $user->company_id, 'public');
                $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
                
                // If the URL is relative, make it absolute
                if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $imageUrl = url($imageUrl);
                }
                
                Log::info('Image uploaded for article', [
                    'user_id' => $user->id,
                    'path' => $path,
                    'url' => $imageUrl,
                ]);
            }

            // Prepare payload
            $payload = [
                'title' => $validated['title'],
                'content' => $validated['content'],
                'categoryId' => $validated['categoryId'],
                'status' => $validated['status'] ?? 'PUBLISHED',
            ];

            if (isset($validated['slug'])) {
                $payload['slug'] = $validated['slug'];
            }
            if (isset($validated['summary'])) {
                $payload['summary'] = $validated['summary'];
            }
            if (isset($validated['isFeatured'])) {
                $payload['isFeatured'] = $validated['isFeatured'];
            }
            if (isset($validated['isBreaking'])) {
                $payload['isBreaking'] = $validated['isBreaking'];
            }
            if (isset($validated['tags']) && is_array($validated['tags'])) {
                $payload['tags'] = $validated['tags'];
            }
            if ($imageUrl) {
                $payload['mainImage'] = $imageUrl;
            }

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $projectUser->external_token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->newsEndpoint, $payload);

            Log::info('TG Calabria create article response', [
                'user_id' => $user->id,
                'status_code' => $response->status(),
                'title' => $validated['title'],
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return response()->json($responseData, 201);
            } else {
                $responseBody = $response->json();
                $errorMessage = $responseBody['message'] ?? 'Failed to create article';
                $errors = $responseBody['errors'] ?? null;
                
                Log::error('TG Calabria create article failed', [
                    'user_id' => $user->id,
                    'status_code' => $response->status(),
                    'error' => $errorMessage,
                    'errors' => $errors,
                    'response_body' => $responseBody,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => $errors,
                ], $response->status());
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create TG Calabria article',
                $e,
                500,
                ['user_id' => $user->id, 'project_id' => $projectId]
            );
        }
    }

    /**
     * Get news statistics for the user.
     */
    /**
     * Fetch all news for user.
     */
    public function getNews(Request $request, int $projectId)
    {
        $user = $request->user();

        // Verify project is TG Calabria
        $project = Project::findOrFail($projectId);
        if ($project->slug !== 'tg-calabria') {
            return response()->json([
                'message' => 'This endpoint is only for TG Calabria project',
            ], 400);
        }

        // Get user's token from company_project_users
        $projectUser = CompanyProjectUser::where('user_id', $user->id)
            ->whereHas('companyProjectAccess', function ($query) use ($projectId) {
                $query->where('project_id', $projectId)
                    ->where('status', 'active');
            })
            ->first();

        if (!$projectUser) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }

        if (!$projectUser->external_token) {
            return response()->json([
                'message' => 'Please login first to get authentication token',
            ], 401);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $projectUser->external_token,
                    'Accept' => 'application/json',
                ])
                ->get($this->newsEndpoint);

            Log::info('TG Calabria news response', [
                'user_id' => $user->id,
                'status_code' => $response->status(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return response()->json($responseData);
            } else {
                $responseBody = $response->json();
                $errorMessage = $responseBody['message'] ?? 'Failed to fetch news';
                
                Log::error('TG Calabria news failed', [
                    'user_id' => $user->id,
                    'status_code' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], $response->status());
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to fetch TG Calabria news',
                $e,
                500,
                ['user_id' => $user->id, 'project_id' => $projectId]
            );
        }
    }

    public function getNewsStats(Request $request, int $projectId)
    {
        $user = $request->user();

        // Verify project is TG Calabria
        $project = Project::findOrFail($projectId);
        if ($project->slug !== 'tg-calabria') {
            return response()->json([
                'message' => 'This endpoint is only for TG Calabria project',
            ], 400);
        }

        // Get user's token and external_user_id from company_project_users
        $projectUser = CompanyProjectUser::where('user_id', $user->id)
            ->whereHas('companyProjectAccess', function ($query) use ($projectId) {
                $query->where('project_id', $projectId)
                    ->where('status', 'active');
            })
            ->first();

        if (!$projectUser) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }

        if (!$projectUser->external_token) {
            return response()->json([
                'message' => 'Please login first to get authentication token',
            ], 401);
        }

        if (!$projectUser->external_user_id) {
            return response()->json([
                'message' => 'User ID not found. Please login again.',
            ], 400);
        }

        try {
            $externalUserId = $projectUser->external_user_id;
            $statsUrl = $this->newsStatsEndpoint . '/' . $externalUserId;

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $projectUser->external_token,
                    'Accept' => 'application/json',
                ])
                ->get($statsUrl);

            Log::info('TG Calabria news stats response', [
                'user_id' => $user->id,
                'external_user_id' => $externalUserId,
                'status_code' => $response->status(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return response()->json($responseData);
            } else {
                $responseBody = $response->json();
                $errorMessage = $responseBody['message'] ?? 'Failed to fetch news statistics';
                
                Log::error('TG Calabria news stats failed', [
                    'user_id' => $user->id,
                    'status_code' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], $response->status());
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to fetch TG Calabria news stats',
                $e,
                500,
                ['user_id' => $user->id, 'project_id' => $projectId]
            );
        }
    }
}
