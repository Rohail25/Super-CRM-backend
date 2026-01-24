<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Campaign;
use App\Services\ActivityLogService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Http;

class CampaignController extends Controller
{
    use HandlesApiErrors;

    protected $activityLogService;
    protected $stripeService;

    public function __construct(ActivityLogService $activityLogService, StripeService $stripeService)
    {
        $this->activityLogService = $activityLogService;
        $this->stripeService = $stripeService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        
        // Super admin sees all campaigns, others see only their company's campaigns
        // The global scope handles this, but we need to bypass it for super admin
        if ($user->isSuperAdmin()) {
            $query = Campaign::withoutGlobalScope('company')->with(['creator', 'project', 'company']);
        } else {
            // For non-super-admin, the global scope already filters by company_id
            // So they will see all campaigns from their company
            $query = Campaign::with(['creator', 'project']);
        }

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('project_id')) {
            $projectId = $request->project_id;
            // For non-super-admin, validate they have access to the project
            if (!$user->isSuperAdmin() && !$user->hasProjectAccess($projectId)) {
                return response()->json([
                    'message' => 'You do not have access to this project',
                ], 403);
            }
            $query->where('project_id', $projectId);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        // Process FormData values before validation
        // Convert string booleans to actual booleans
        if ($request->has('track_clicks')) {
            $request->merge(['track_clicks' => filter_var($request->track_clicks, FILTER_VALIDATE_BOOLEAN)]);
        }
        if ($request->has('track_opens')) {
            $request->merge(['track_opens' => filter_var($request->track_opens, FILTER_VALIDATE_BOOLEAN)]);
        }
        
        // Parse JSON strings to arrays for target_audience and target_criteria
        if ($request->has('target_audience')) {
            if (is_string($request->target_audience)) {
                $decoded = json_decode($request->target_audience, true);
                $request->merge(['target_audience' => is_array($decoded) ? $decoded : []]);
            } elseif (!is_array($request->target_audience)) {
                $request->merge(['target_audience' => []]);
            }
        } else {
            $request->merge(['target_audience' => []]);
        }
        
        if ($request->has('target_criteria')) {
            if (is_string($request->target_criteria)) {
                $decoded = json_decode($request->target_criteria, true);
                $request->merge(['target_criteria' => is_array($decoded) ? $decoded : []]);
            } elseif (!is_array($request->target_criteria)) {
                $request->merge(['target_criteria' => []]);
            }
        } else {
            $request->merge(['target_criteria' => []]);
        }

        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
            'target_link' => 'required|string|url|max:500',
            'type' => ['required', Rule::in(['BANNER_TOP', 'BANNER_SIDE', 'INLINE', 'FOOTER', 'SLIDER', 'TICKER', 'POPUP', 'STICKY'])],
            'status' => ['sometimes', Rule::in(['draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled'])],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'budget' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'target_audience' => 'nullable|array',
            'target_criteria' => 'nullable|array',
            'content_data' => 'nullable|array',
            'settings' => 'nullable|array',
            'track_clicks' => 'sometimes|boolean',
            'track_opens' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        
        // Validate project access if project_id is provided
        if (isset($validated['project_id']) && !$user->hasProjectAccess($validated['project_id'])) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }
        
        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $path = $image->store('campaigns/' . $user->company_id, 'public');
            $validated['image_path'] = $path;
        }
        
        // Validate target_link URL format (required field, so it should always be present)
        if (isset($validated['target_link']) && !empty($validated['target_link'])) {
            if (!filter_var($validated['target_link'], FILTER_VALIDATE_URL)) {
                return response()->json([
                    'message' => 'The target link must be a valid URL.',
                ], 422);
            }
        }
        
        // Handle target_link from settings JSON if sent that way (for backward compatibility)
        if ($request->has('settings') && !isset($validated['target_link'])) {
            if (is_string($request->settings)) {
                $decoded = json_decode($request->settings, true);
                if (is_array($decoded) && isset($decoded['target_link']) && !empty(trim($decoded['target_link']))) {
                    $validated['target_link'] = $decoded['target_link'];
                }
            } elseif (is_array($request->settings) && isset($request->settings['target_link']) && !empty(trim($request->settings['target_link']))) {
                $validated['target_link'] = $request->settings['target_link'];
            }
        }
        
        $validated['company_id'] = $user->company_id;
        $validated['created_by'] = $user->id;
        // Always set status to 'draft' (pending) when creating - admin will approve later
        $validated['status'] = 'draft';

        try {
            $campaign = DB::transaction(function () use ($validated) {
                $campaign = Campaign::create($validated);
                $this->activityLogService->logCreated($campaign);
                return $campaign;
            });

            return response()->json($campaign->load(['creator', 'project']), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (\Exception $e) {
            // Check for database exceptions first
            $dbError = $this->handleDatabaseException($e);
            if ($dbError) {
                return $dbError;
            }

            return $this->errorResponse(
                'Failed to create campaign',
                $e,
                500,
                ['user_id' => $user->id, 'company_id' => $user->company_id]
            );
        }
    }

    public function show(Campaign $campaign)
    {
        $this->activityLogService->logViewed($campaign);
        return response()->json($campaign->load(['creator', 'project', 'company']));
    }

    public function update(Request $request, Campaign $campaign)
    {
        $user = $request->user();
        
        // Process FormData values before validation
        // Convert string booleans to actual booleans
        if ($request->has('track_clicks')) {
            $request->merge(['track_clicks' => filter_var($request->track_clicks, FILTER_VALIDATE_BOOLEAN)]);
        }
        if ($request->has('track_opens')) {
            $request->merge(['track_opens' => filter_var($request->track_opens, FILTER_VALIDATE_BOOLEAN)]);
        }
        
        // Handle target_link - normalize empty strings to null before validation
        if ($request->has('target_link')) {
            $targetLink = trim($request->target_link);
            if (empty($targetLink)) {
                $request->merge(['target_link' => null]);
            }
        }
        
        // Parse JSON strings to arrays for target_audience and target_criteria
        if ($request->has('target_audience')) {
            if (is_string($request->target_audience)) {
                $decoded = json_decode($request->target_audience, true);
                $request->merge(['target_audience' => is_array($decoded) ? $decoded : []]);
            } elseif (!is_array($request->target_audience)) {
                $request->merge(['target_audience' => []]);
            }
        }
        
        if ($request->has('target_criteria')) {
            if (is_string($request->target_criteria)) {
                $decoded = json_decode($request->target_criteria, true);
                $request->merge(['target_criteria' => is_array($decoded) ? $decoded : []]);
            } elseif (!is_array($request->target_criteria)) {
                $request->merge(['target_criteria' => []]);
            }
        }
        
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
            'target_link' => 'nullable|string|url|max:500', // Optional for updates, but if provided must be valid URL
            'type' => ['sometimes', Rule::in(['BANNER_TOP', 'BANNER_SIDE', 'INLINE', 'FOOTER', 'SLIDER', 'TICKER', 'POPUP', 'STICKY'])],
            'status' => ['sometimes', Rule::in(['draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled'])],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'budget' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'target_audience' => 'nullable|array',
            'target_criteria' => 'nullable|array',
            'content_data' => 'nullable|array',
            'settings' => 'nullable|array',
            'track_clicks' => 'sometimes|boolean',
            'track_opens' => 'sometimes|boolean',
        ]);

        // Validate project access if project_id is being updated
        if (isset($validated['project_id']) && !$user->hasProjectAccess($validated['project_id'])) {
            return response()->json([
                'message' => 'You do not have access to this project',
            ], 403);
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($campaign->image_path && Storage::disk('public')->exists($campaign->image_path)) {
                Storage::disk('public')->delete($campaign->image_path);
            }
            
            $image = $request->file('image');
            $path = $image->store('campaigns/' . $user->company_id, 'public');
            $validated['image_path'] = $path;
        }

        // Validate target_link URL format if provided
        if (isset($validated['target_link']) && !empty($validated['target_link'])) {
            if (!filter_var($validated['target_link'], FILTER_VALIDATE_URL)) {
                return response()->json([
                    'message' => 'The target link must be a valid URL.',
                ], 422);
            }
        } else {
            // If target_link is empty or not provided, set it to null
            $validated['target_link'] = null;
        }
        
        // Handle target_link from settings JSON if sent that way (for backward compatibility)
        if ($request->has('settings') && !isset($validated['target_link'])) {
            if (is_string($request->settings)) {
                $decoded = json_decode($request->settings, true);
                if (is_array($decoded) && isset($decoded['target_link']) && !empty(trim($decoded['target_link']))) {
                    $validated['target_link'] = $decoded['target_link'];
                }
            } elseif (is_array($request->settings) && isset($request->settings['target_link']) && !empty(trim($request->settings['target_link']))) {
                $validated['target_link'] = $request->settings['target_link'];
            }
        }

        $oldValues = $campaign->getAttributes();

        $campaign = DB::transaction(function () use ($campaign, $validated, $oldValues) {
            $campaign->update($validated);
            
            // If status changed to active and start_date is in the past, update it
            if (isset($validated['status']) && $validated['status'] === 'active' && !$campaign->start_date) {
                $campaign->start_date = now();
            }

            $this->activityLogService->logUpdated($campaign, $oldValues, $campaign->getAttributes());
            return $campaign;
        });

        return response()->json($campaign->load(['creator', 'project']));
    }

    public function destroy(Campaign $campaign)
    {
        $this->activityLogService->logDeleted($campaign);
        $campaign->delete();
        return response()->json(null, 204);
    }

    /**
     * Get campaign statistics.
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        
        // Super admin sees all campaigns, others see only their company's campaigns
        if ($user->isSuperAdmin()) {
            $query = Campaign::withoutGlobalScope('company');
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }
        } else {
            $query = Campaign::where('company_id', $user->company_id);
        }

        $stats = [
            'total_campaigns' => (clone $query)->count(),
            'active_campaigns' => (clone $query)->where('status', 'active')->count(),
            'total_sent' => (clone $query)->sum('sent_count') ?? 0,
            'total_opened' => (clone $query)->sum('opened_count') ?? 0,
            'total_clicked' => (clone $query)->sum('clicked_count') ?? 0,
            'total_converted' => (clone $query)->sum('converted_count') ?? 0,
            'total_budget' => (clone $query)->sum('budget') ?? 0,
            'total_spent' => (clone $query)->sum('spent') ?? 0,
        ];

        // Calculate averages from active campaigns
        $activeCampaigns = (clone $query)->where('status', 'active')->get();
        if ($activeCampaigns->count() > 0) {
            $stats['avg_open_rate'] = $activeCampaigns->avg('open_rate') ?? 0;
            $stats['avg_click_rate'] = $activeCampaigns->avg('click_rate') ?? 0;
            $stats['avg_conversion_rate'] = $activeCampaigns->avg('conversion_rate') ?? 0;
        } else {
            $stats['avg_open_rate'] = 0;
            $stats['avg_click_rate'] = 0;
            $stats['avg_conversion_rate'] = 0;
        }

        return response()->json($stats);
    }

    /**
     * Create Stripe checkout session for campaign payment.
     */
    public function createPaymentCheckout(Request $request, Campaign $campaign)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $campaign->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        // Validate campaign has budget
        if (!$campaign->budget || $campaign->budget <= 0) {
            return response()->json([
                'message' => 'Campaign must have a valid budget to proceed with payment',
            ], 400);
        }

        // Check if already paid
        if ($campaign->payment_status === 'paid') {
            return response()->json([
                'message' => 'Campaign payment has already been completed',
            ], 400);
        }

        try {
            $result = $this->stripeService->createCampaignCheckoutSession($campaign);

            return response()->json([
                'session_id' => $result['session_id'],
                'checkout_url' => $result['checkout_url'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                'Invalid request: ' . $e->getMessage(),
                $e,
                400,
                ['campaign_id' => $campaign->id]
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return $this->errorResponse(
                'Payment processing error',
                $e,
                500,
                [
                    'campaign_id' => $campaign->id,
                    'stripe_code' => $e->getStripeCode(),
                ]
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create payment checkout',
                $e,
                500,
                ['campaign_id' => $campaign->id]
            );
        }
    }

    /**
     * Handle payment success callback.
     */
    public function handlePaymentSuccess(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'campaign_id' => 'required|exists:campaigns,id',
        ]);

        try {
            $session = $this->stripeService->getCheckoutSession($request->session_id);
            $campaign = Campaign::findOrFail($request->campaign_id);

            // Verify session metadata matches campaign
            if ($session->metadata->campaign_id != $campaign->id) {
                return response()->json([
                    'message' => 'Invalid payment session',
                    'error' => [
                        'message' => 'The payment session does not match the campaign.',
                        'type' => 'PaymentSessionMismatch',
                        'session_campaign_id' => $session->metadata->campaign_id ?? null,
                        'requested_campaign_id' => $campaign->id,
                    ],
                ], 400);
            }

            // Update campaign payment status
            if ($session->payment_status === 'paid') {
                $campaign->update([
                    'payment_status' => 'paid',
                    'stripe_payment_intent_id' => $session->payment_intent,
                ]);

                return response()->json([
                    'message' => 'Payment successful',
                    'campaign' => $campaign->load(['creator', 'project']),
                ]);
            } else {
                $campaign->update([
                    'payment_status' => 'pending',
                ]);

                return response()->json([
                    'message' => 'Payment is pending',
                    'campaign' => $campaign->load(['creator', 'project']),
                ]);
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to process payment',
                $e,
                500,
                ['campaign_id' => $request->campaign_id, 'session_id' => $request->session_id]
            );
        }
    }

    /**
     * Activate campaign by creating ad in external system.
     */
    public function activate(Request $request, Campaign $campaign)
    {
        $user = $request->user();

        // Only super admin can activate campaigns
        if (!$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Only super admin can activate campaigns',
            ], 403);
        }

        // Check if payment status is paid
        if ($campaign->payment_status !== 'paid') {
            return response()->json([
                'message' => 'Campaign must be paid before activation',
            ], 400);
        }

        // Validate required fields
        if (!$campaign->name || !$campaign->type || !$campaign->image_path || !$campaign->budget || !$campaign->start_date || !$campaign->end_date) {
            return response()->json([
                'message' => 'Campaign is missing required fields for activation',
            ], 400);
        }

        try {
            // Step 1: Login to external API
            $loginResponse = Http::post('https://api.tgcalabriareport.com/api/v1/auth/login', [
                'email' => 'admin@gmail.com',
                'password' => '11221122',
            ]);

            if (!$loginResponse->successful()) {
                Log::error('Failed to login to external API', [
                    'campaign_id' => $campaign->id,
                    'status_code' => $loginResponse->status(),
                    'response' => $loginResponse->body(),
                ]);
                return response()->json([
                    'message' => 'Failed to authenticate with external API: ' . ($loginResponse->json()['message'] ?? 'HTTP ' . $loginResponse->status()),
                ], 500);
            }

            $loginData = $loginResponse->json();
            
            // Extract token from response - it can be in different locations
            $token = $loginData['data']['token'] ?? 
                     $loginData['token'] ?? 
                     $loginData['access_token'] ?? 
                     ($loginData['data']['access_token'] ?? null);

            if (!$token) {
                Log::error('No token received from login', [
                    'campaign_id' => $campaign->id,
                    'response' => $loginData,
                    'status_code' => $loginResponse->status(),
                ]);
                return response()->json([
                    'message' => 'Failed to get authentication token. Please check the API response structure.',
                ], 500);
            }

            // Step 2: Get image URL
            $imageUrl = Storage::disk('public')->url($campaign->image_path);
            // If the URL is relative, make it absolute
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = url($imageUrl);
            }

            // Step 3: Create ad in external API
            // Parse dates and ensure they're in UTC timezone with proper ISO 8601 format
            // Expected format: 2024-06-01T00:00:00.000Z (ISO 8601 with milliseconds and Z suffix)
            $startDate = $campaign->start_date instanceof \Carbon\Carbon 
                ? $campaign->start_date->utc() 
                : \Carbon\Carbon::parse($campaign->start_date)->utc();
            $endDate = $campaign->end_date instanceof \Carbon\Carbon 
                ? $campaign->end_date->utc() 
                : \Carbon\Carbon::parse($campaign->end_date)->utc();
            
            // Format dates as ISO 8601 with milliseconds and Z suffix: 2024-06-01T00:00:00.000Z
            // The 'v' format gives milliseconds (000-999), and we append 'Z' for UTC/Zulu time
            $startDateFormatted = $startDate->format('Y-m-d\TH:i:s.v') . 'Z';
            $endDateFormatted = $endDate->format('Y-m-d\TH:i:s.v') . 'Z';
            
            // Get target link from direct field, settings, or content_data (for backward compatibility)
            $targetLink = $campaign->target_link;
            
            if (!$targetLink) {
                // Fallback to settings or content_data for backward compatibility
                if ($campaign->settings && is_array($campaign->settings) && isset($campaign->settings['target_link'])) {
                    $targetLink = $campaign->settings['target_link'];
                } elseif ($campaign->content_data && is_array($campaign->content_data) && isset($campaign->content_data['target_link'])) {
                    $targetLink = $campaign->content_data['target_link'];
                }
            }
            
            if (!$targetLink) {
                return response()->json([
                    'message' => 'Campaign must have a target link. Please add a target link in the campaign form.',
                ], 400);
            }
            
            // Determine position based on type
            $position = 'BODY';
            if ($campaign->type === 'BANNER_TOP') {
                $position = 'HEADER';
            } elseif ($campaign->type === 'FOOTER') {
                $position = 'FOOTER';
            } elseif (in_array($campaign->type, ['BANNER_SIDE', 'STICKY'])) {
                $position = 'SIDEBAR';
            }
            
            $adData = [
                'title' => $campaign->name,
                'type' => $campaign->type,
                'imageUrl' => $imageUrl,
                'targetLink' => $targetLink,
                'position' => $position,
                'startDate' => $startDateFormatted,
                'endDate' => $endDateFormatted,
                'price' => (float) $campaign->budget,
            ];

            $adResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post('https://api.tgcalabriareport.com/api/v1/crm/ads', $adData);

            if (!$adResponse->successful()) {
                $responseData = $adResponse->json();
                $errorMessage = $responseData['message'] ?? 'Unknown error';
                $validationErrors = $responseData['errors'] ?? $responseData['error'] ?? null;
                
                Log::error('Failed to create ad in external API', [
                    'campaign_id' => $campaign->id,
                    'status_code' => $adResponse->status(),
                    'response' => $adResponse->body(),
                    'ad_data' => $adData,
                    'validation_errors' => $validationErrors,
                ]);
                
                $errorDetails = $errorMessage;
                if ($validationErrors) {
                    if (is_array($validationErrors)) {
                        $errorDetails .= ': ' . json_encode($validationErrors);
                    } else {
                        $errorDetails .= ': ' . $validationErrors;
                    }
                }
                
                return response()->json([
                    'message' => 'Failed to create ad in external system: ' . $errorDetails,
                    'validation_errors' => $validationErrors,
                ], 500);
            }

            // Update campaign status to active
            $campaign->update(['status' => 'active']);

            return response()->json([
                'message' => 'Campaign activated successfully',
                'campaign' => $campaign->load(['creator', 'project']),
                'ad_response' => $adResponse->json(),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to activate campaign',
                $e,
                500,
                ['campaign_id' => $campaign->id]
            );
        }
    }
}
