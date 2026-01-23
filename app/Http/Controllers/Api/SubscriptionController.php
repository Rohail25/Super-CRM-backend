<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class SubscriptionController extends Controller
{
    public function __construct(
        private StripeService $stripeService
    ) {}

    /**
     * Get company's current subscription.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        if (!$company) {
            return response()->json([
                'subscription' => null,
                'plan' => null,
            ]);
        }

        // Refresh company to get latest subscription status
        $company->refresh();
        $subscription = $company->subscription;
        
        if (!$subscription) {
            return response()->json([
                'subscription' => null,
                'plan' => null,
                'company_status' => $company->status,
                'subscription_status' => $company->subscription_status,
            ]);
        }

        return response()->json([
            'subscription' => $subscription->load('plan'),
            'plan' => $subscription->plan,
            'company_status' => $company->status,
            'subscription_status' => $company->subscription_status,
        ]);
    }

    /**
     * List available subscription plans (public).
     */
    public function plans()
    {
        $plans = SubscriptionPlan::active()->get();

        return response()->json($plans);
    }

    /**
     * Create Stripe checkout session (minimal - just returns session ID for frontend redirect).
     */
    public function createCheckoutSession(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        // Only company admin can create subscription
        if (!$user->isCompanyAdmin() && !$user->isSuperAdmin()) {
            return response()->json(['message' => 'Only company admin can create subscription'], 403);
        }

        // Check if company can subscribe
        // Allow if: company is approved OR company is active but has no active subscription
        $hasActiveSubscription = $company->hasActiveSubscription();
        $canSubscribe = ($company->status === 'approved' || $company->subscription_status === 'approved') ||
                       ($company->status === 'active' && !$hasActiveSubscription);
        
        if (!$canSubscribe && $hasActiveSubscription) {
            return response()->json([
                'message' => 'Company already has an active subscription',
            ], 400);
        }

        if (!$canSubscribe) {
            return response()->json([
                'message' => 'Company must be approved before subscribing',
            ], 403);
        }

        // Get plan from request or use active plan
        $planId = $request->input('plan_id');
        $plan = $planId 
            ? SubscriptionPlan::find($planId)
            : SubscriptionPlan::active()->first();
            
        if (!$plan) {
            return response()->json([
                'message' => 'No subscription plan available',
            ], 404);
        }

        try {
            // Create checkout session and return checkout URL for direct redirect
            $checkoutUrl = $this->stripeService->createCheckoutSession($company, $plan);

            return response()->json([
                'checkout_url' => $checkoutUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create checkout session', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create checkout session',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Activate subscription after successful payment (called by frontend).
     * Simplified: If Stripe redirected to success URL, just activate it - no verification for now.
     * This endpoint is public (no auth required) because Stripe redirects here.
     */
    public function activateSubscription(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'company_id' => 'required|integer',
            'plan_id' => 'required|integer',
            'payment_intent_id' => 'nullable|string',
        ]);

        try {
            // Simple activation - no verification for now
            $companyId = $request->company_id;
            $planId = $request->plan_id;
            $paymentIntentId = $request->payment_intent_id;

            // Find company and plan
            $company = Company::find($companyId);
            if (!$company) {
                return response()->json(['message' => 'Company not found'], 404);
            }

            $plan = SubscriptionPlan::find($planId);
            if (!$plan) {
                return response()->json(['message' => 'Subscription plan not found'], 404);
            }

            if (!$planId) {
                return response()->json([
                    'message' => 'Subscription plan not found in session',
                ], 400);
            }

            $plan = SubscriptionPlan::find($planId);
            if (!$plan) {
                return response()->json(['message' => 'Subscription plan not found'], 404);
            }

            // Check if subscription already exists and is active (idempotency)
            $existingSubscription = $company->subscription;
            if ($existingSubscription && $existingSubscription->status === 'active') {
                return response()->json([
                    'message' => 'Subscription already active',
                    'subscription' => $existingSubscription->load('plan'),
                    'company' => $company->fresh(['subscription']),
                ]);
            }

            // Activate subscription (payment was successful - Stripe redirected here)
            $now = now();
            $periodEnd = match($plan->interval) {
                'month' => $now->copy()->addMonth(),
                'year' => $now->copy()->addYear(),
                default => $now->copy()->addMonth(),
            };

            $subscription = Subscription::updateOrCreate(
                ['company_id' => $company->id],
                [
                    'subscription_plan_id' => $plan->id,
                    'stripe_customer_id' => $company->stripe_customer_id,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'status' => 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $periodEnd,
                ]
            );

            // Activate company
            $company->update([
                'subscription_status' => 'active',
                'status' => 'active',
            ]);

            // Activate admin user if not already active
            $adminUser = $company->users()->where('role', 'company_admin')->first();
            if ($adminUser && $adminUser->status !== 'active') {
                $adminUser->update(['status' => 'active']);
            }

            return response()->json([
                'message' => 'Subscription activated successfully',
                'subscription' => $subscription->load('plan'),
                'company' => $company->fresh(['subscription']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to activate subscription', [
                'company_id' => $companyId ?? null,
                'session_id' => $request->session_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to activate subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Handle successful checkout redirect (legacy - kept for backward compatibility).
     */
    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');
        $isRenewal = $request->query('is_renewal') === '1';

        if (!$sessionId) {
            return response()->json([
                'message' => 'Missing session ID',
            ], 400);
        }

        try {
            $session = $this->stripeService->getCheckoutSession($sessionId);

            // Check if payment was successful
            if ($session->payment_status === 'paid') {
                // Get metadata from session (works even if user not authenticated)
                $companyId = $session->metadata->company_id ?? null;
                $planId = $session->metadata->subscription_plan_id ?? null;
                $paymentIntentId = $session->payment_intent ?? null;
                
                if ($companyId && $planId) {
                    $company = \App\Models\Company::find($companyId);
                    $plan = \App\Models\SubscriptionPlan::find($planId);
                    
                    if ($company && $plan) {
                        // Check if subscription already exists (webhook may have created it)
                        $subscription = $company->subscription;
                        
                        // If subscription doesn't exist or isn't active, create/activate it manually
                        if (!$subscription || $subscription->status !== 'active') {
                            // Manually activate subscription (webhook may not have fired yet)
                            $now = now();
                            $periodEnd = match($plan->interval) {
                                'month' => $now->copy()->addMonth(),
                                'year' => $now->copy()->addYear(),
                                default => $now->copy()->addMonth(),
                            };
                            
                            $subscription = \App\Models\Subscription::updateOrCreate(
                                ['company_id' => $company->id],
                                [
                                    'subscription_plan_id' => $plan->id,
                                    'stripe_customer_id' => $company->stripe_customer_id,
                                    'stripe_payment_intent_id' => $paymentIntentId,
                                    'status' => 'active',
                                    'current_period_start' => $now,
                                    'current_period_end' => $periodEnd,
                                ]
                            );
                            
                            // Activate company
                            $company->update([
                                'subscription_status' => 'active',
                                'status' => 'active',
                            ]);
                            
                            // Activate admin user if not already active
                            $adminUser = $company->users()->where('role', 'company_admin')->first();
                            if ($adminUser && $adminUser->status !== 'active') {
                                $adminUser->update(['status' => 'active']);
                            }
                        }
                        
                        return response()->json([
                            'message' => $isRenewal ? 'Subscription renewed successfully' : 'Subscription activated successfully',
                            'subscription' => $subscription->load('plan'),
                            'company' => $company->fresh(['subscription']),
                        ]);
                    }
                }
            }

            return response()->json([
                'message' => 'Payment completed. Processing subscription...',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve checkout session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to verify checkout session',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Handle canceled checkout redirect.
     */
    public function cancel(Request $request)
    {
        return response()->json([
            'message' => 'Checkout was canceled. You can try again anytime.',
        ]);
    }

    /**
     * Handle Stripe webhooks.
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (!$webhookSecret) {
            Log::error('Stripe webhook secret not configured');
            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid Stripe webhook payload', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid Stripe webhook signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Handle the event
        try {
            $this->stripeService->handleWebhook($event->toArray());
            
            return response()->json(['received' => true]);
        } catch (\Exception $e) {
            Log::error('Error processing webhook', [
                'event_type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * List all subscription plans (Super Admin only).
     */
    public function listPlans(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $plans = SubscriptionPlan::all();

        return response()->json($plans);
    }

    /**
     * Create a new subscription plan (Super Admin only).
     */
    public function createPlan(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->isSuperAdmin()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Preprocess request data
            $requestData = $request->all();
            
            // Convert amount to integer if it's a float or string
            if (isset($requestData['amount'])) {
                $requestData['amount'] = (int) $requestData['amount'];
            }
            
            // Ensure currency is exactly 3 characters (preserve case)
            if (isset($requestData['currency'])) {
                $requestData['currency'] = substr(strtoupper($requestData['currency']), 0, 3);
            }
            
            // Preprocess boolean values
            if (isset($requestData['is_active'])) {
                if (is_string($requestData['is_active'])) {
                    $requestData['is_active'] = filter_var($requestData['is_active'], FILTER_VALIDATE_BOOLEAN);
                } elseif (!is_bool($requestData['is_active'])) {
                    $requestData['is_active'] = (bool) $requestData['is_active'];
                }
            }
            
            // Handle features - convert empty array to null, ensure it's a valid array
            if (isset($requestData['features'])) {
                if (is_string($requestData['features'])) {
                    // Try to decode if it's a JSON string
                    $decoded = json_decode($requestData['features'], true);
                    $requestData['features'] = is_array($decoded) ? $decoded : null;
                } elseif (is_array($requestData['features']) && empty($requestData['features'])) {
                    $requestData['features'] = null;
                } elseif (!is_array($requestData['features'])) {
                    $requestData['features'] = null;
                }
            }
            
            $request->merge($requestData);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'required|integer|min:0',
                'currency' => 'required|string|size:3',
                'interval' => 'required|in:month,year',
                'features' => 'nullable|array',
                'is_active' => 'nullable|boolean',
            ]);

            // Ensure is_active has a default value
            if (!isset($validated['is_active'])) {
                $validated['is_active'] = true;
            }
            
            // Ensure features is null if empty array
            if (isset($validated['features']) && is_array($validated['features']) && empty($validated['features'])) {
                $validated['features'] = null;
            }

            $plan = SubscriptionPlan::create($validated);

            return response()->json($plan, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Subscription plan validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all(),
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create subscription plan', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);
            return response()->json([
                'message' => 'Failed to create subscription plan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a subscription plan (Super Admin only).
     */
    public function updatePlan(Request $request, SubscriptionPlan $plan)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'stripe_price_id' => 'nullable|string|max:255',
            'amount' => 'sometimes|integer|min:0',
            'currency' => 'sometimes|string|size:3',
            'interval' => 'sometimes|in:month,year',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $plan->update($validated);

        return response()->json($plan);
    }

    /**
     * Delete a subscription plan (Super Admin only).
     */
    public function deletePlan(Request $request, SubscriptionPlan $plan)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if plan has active subscriptions
        $activeSubscriptions = $plan->subscriptions()->whereIn('status', ['active', 'trialing'])->count();
        
        if ($activeSubscriptions > 0) {
            return response()->json([
                'message' => 'Cannot delete plan with active subscriptions',
                'active_subscriptions' => $activeSubscriptions,
            ], 400);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan deleted successfully']);
    }

    /**
     * Cancel subscription (managed by our system, not Stripe).
     */
    public function cancelSubscription(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        // Only company admin can cancel subscription
        if (!$user->isCompanyAdmin() && !$user->isSuperAdmin()) {
            return response()->json(['message' => 'Only company admin can cancel subscription'], 403);
        }

        $subscription = $company->subscription;
        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found'], 404);
        }

        $atPeriodEnd = $request->input('at_period_end', true);

        try {
            if ($atPeriodEnd) {
                // Cancel at period end
                $subscription->update([
                    'cancel_at_period_end' => true,
                ]);

                return response()->json([
                    'message' => 'Subscription will be canceled at the end of the billing period',
                    'subscription' => $subscription->load('plan'),
                ]);
            } else {
                // Cancel immediately
                $subscription->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                    'cancel_at_period_end' => false,
                ]);

                $company->update([
                    'subscription_status' => 'canceled',
                    'status' => 'suspended',
                ]);

                return response()->json([
                    'message' => 'Subscription canceled immediately',
                    'subscription' => $subscription->load('plan'),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to cancel subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Create renewal checkout session (when subscription is about to expire).
     */
    public function createRenewalCheckout(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        // Only company admin can renew subscription
        if (!$user->isCompanyAdmin() && !$user->isSuperAdmin()) {
            return response()->json(['message' => 'Only company admin can renew subscription'], 403);
        }

        $subscription = $company->subscription;
        if (!$subscription) {
            return response()->json(['message' => 'No subscription found'], 404);
        }

        $plan = $subscription->plan;
        if (!$plan) {
            return response()->json(['message' => 'Subscription plan not found'], 404);
        }

        try {
            $checkoutUrl = $this->stripeService->createCheckoutSession($company, $plan, true);

            return response()->json([
                'checkout_url' => $checkoutUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create renewal checkout session', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create renewal checkout session',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}

