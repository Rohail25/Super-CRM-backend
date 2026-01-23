<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Campaign;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $secretKey = config('services.stripe.secret');
        
        if (empty($secretKey)) {
            throw new \RuntimeException('Stripe secret key is not configured. Please set STRIPE_SECRET in your .env file.');
        }
        
        $this->stripe = new StripeClient($secretKey);
    }

    /**
     * Create a Stripe customer for a company.
     */
    public function createCustomer(Company $company, string $email): string
    {
        try {
            $customer = $this->stripe->customers->create([
                'email' => $email,
                'name' => $company->name,
                'metadata' => [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                ],
            ]);

            $company->update([
                'stripe_customer_id' => $customer->id,
            ]);

            return $customer->id;
        } catch (ApiErrorException $e) {
            Log::error('Failed to create Stripe customer', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create checkout session and return session ID (for frontend redirect with Stripe.js).
     */
    public function createCheckoutSessionId(Company $company, SubscriptionPlan $plan, bool $isRenewal = false): string
    {
        try {
            if (!$company->stripe_customer_id) {
                $adminUser = $company->users()->where('role', 'company_admin')->first();
                $email = $adminUser?->email ?? $company->name . '@example.com';
                $this->createCustomer($company, $email);
                $company->refresh();
            }

            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
            
            $session = $this->stripe->checkout->sessions->create([
                'customer' => $company->stripe_customer_id,
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $plan->currency,
                        'product_data' => [
                            'name' => $plan->name,
                            'description' => $plan->description ?? '',
                        ],
                        'unit_amount' => $plan->amount, // Amount in cents
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment', // One-time payment mode
                'success_url' => $frontendUrl . '/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontendUrl . '/subscription/cancel',
                'metadata' => [
                    'company_id' => $company->id,
                    'subscription_plan_id' => $plan->id,
                    'is_renewal' => $isRenewal ? '1' : '0',
                ],
            ]);

            return $session->id; // Return session ID instead of URL
        } catch (ApiErrorException $e) {
            Log::error('Failed to create Stripe checkout session', [
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create checkout session and return URL (legacy method - kept for backward compatibility).
     */
    public function createCheckoutSession(Company $company, SubscriptionPlan $plan, bool $isRenewal = false): string
    {
        try {
            // Ensure company has a Stripe customer ID
            if (!$company->stripe_customer_id) {
                $adminUser = $company->users()->where('role', 'company_admin')->first();
                $email = $adminUser?->email ?? $company->name . '@example.com';
                $this->createCustomer($company, $email);
                $company->refresh();
            }

            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));

            // Create a one-time payment (not subscription)
            $lineItem = [
                'price_data' => [
                    'currency' => $plan->currency,
                    'product_data' => [
                        'name' => $plan->name,
                        'description' => $plan->description ?? '',
                    ],
                    'unit_amount' => $plan->amount, // Amount in cents
                    // Note: 'recurring' field is NOT included for one-time payments
                ],
                'quantity' => 1,
            ];

            $session = $this->stripe->checkout->sessions->create([
                'customer' => $company->stripe_customer_id,
                'payment_method_types' => ['card'],
                'line_items' => [$lineItem],
                'mode' => 'payment', // One-time payment mode
                'success_url' => $frontendUrl . '/subscription/success?session_id={CHECKOUT_SESSION_ID}&company_id=' . $company->id . '&plan_id=' . $plan->id . '&is_renewal=' . ($isRenewal ? '1' : '0'),
                'cancel_url' => $frontendUrl . '/subscription/cancel',
                'metadata' => [
                    'company_id' => $company->id,
                    'subscription_plan_id' => $plan->id,
                    'is_renewal' => $isRenewal ? '1' : '0',
                ],
            ]);

            return $session->url;
        } catch (ApiErrorException $e) {
            Log::error('Failed to create Stripe checkout session', [
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    /**
     * Create checkout session for campaign payment.
     */
    public function createCampaignCheckoutSession(Campaign $campaign): array
    {
        try {
            $company = $campaign->company;
            
            // Validate budget
            if (!$campaign->budget || $campaign->budget <= 0) {
                throw new \InvalidArgumentException('Campaign budget must be greater than 0');
            }

            // Ensure company has a Stripe customer ID
            if (!$company->stripe_customer_id) {
                $adminUser = $company->users()->where('role', 'company_admin')->first();
                $email = $adminUser?->email ?? $company->name . '@example.com';
                $this->createCustomer($company, $email);
                $company->refresh();
            }

            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
            $currency = strtolower($campaign->currency ?? 'usd');
            
            // Validate currency (Stripe supports 3-letter ISO currency codes)
            if (strlen($currency) !== 3) {
                $currency = 'usd'; // Default to USD if invalid
            }
            
            // Convert budget to cents (ensure it's a positive integer)
            $amount = (int)round($campaign->budget * 100);
            
            if ($amount < 50) { // Stripe minimum is $0.50 (50 cents)
                throw new \InvalidArgumentException('Campaign budget must be at least 0.50 in the selected currency');
            }

            Log::info('Creating Stripe checkout session for campaign', [
                'campaign_id' => $campaign->id,
                'amount' => $amount,
                'currency' => $currency,
                'budget' => $campaign->budget,
            ]);

            $session = $this->stripe->checkout->sessions->create([
                'customer' => $company->stripe_customer_id,
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => 'Campaign Payment: ' . $campaign->name,
                            'description' => $campaign->description ?? 'Campaign budget payment',
                        ],
                        'unit_amount' => $amount,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $frontendUrl . '/marketing?session_id={CHECKOUT_SESSION_ID}&campaign_id=' . $campaign->id,
                'cancel_url' => $frontendUrl . '/marketing',
                'metadata' => [
                    'company_id' => (string)$company->id,
                    'campaign_id' => (string)$campaign->id,
                    'type' => 'campaign_payment',
                ],
            ]);

            // Verify session URL exists
            if (empty($session->url)) {
                Log::error('Stripe session created but URL is empty', [
                    'campaign_id' => $campaign->id,
                    'session_id' => $session->id,
                ]);
                throw new \RuntimeException('Stripe checkout session URL is missing');
            }

            // Update campaign with checkout session ID
            $campaign->update([
                'stripe_checkout_session_id' => $session->id,
                'payment_status' => 'pending',
            ]);

            Log::info('Stripe checkout session created successfully', [
                'campaign_id' => $campaign->id,
                'session_id' => $session->id,
                'checkout_url' => $session->url,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            return [
                'session_id' => $session->id,
                'checkout_url' => $session->url,
            ];
        } catch (ApiErrorException $e) {
            Log::error('Failed to create campaign checkout session', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
                'stripe_error' => $e->getStripeCode() ?? null,
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create campaign checkout session', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get a checkout session from Stripe.
     */
    public function getCheckoutSession(string $sessionId): object
    {
        try {
            return $this->stripe->checkout->sessions->retrieve($sessionId);
        } catch (ApiErrorException $e) {
            Log::error('Failed to retrieve Stripe checkout session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle webhook events from Stripe.
     * Only handles payment success/failure - subscription management is done by our system.
     */
    public function handleWebhook(array $payload): void
    {
        $eventType = $payload['type'] ?? null;
        $data = $payload['data']['object'] ?? null;

        if (!$eventType || !$data) {
            Log::warning('Invalid webhook payload', ['payload' => $payload]);
            return;
        }

        try {
            match ($eventType) {
                'checkout.session.completed' => $this->handleCheckoutSessionCompleted($data),
                'payment_intent.succeeded' => $this->handlePaymentSucceeded($data),
                'payment_intent.payment_failed' => $this->handlePaymentFailed($data),
                default => Log::info('Unhandled webhook event', ['type' => $eventType]),
            };
        } catch (\Exception $e) {
            Log::error('Error handling webhook event', [
                'type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle checkout.session.completed event (payment successful).
     */
    private function handleCheckoutSessionCompleted(array $session): void
    {
        $companyId = $session['metadata']['company_id'] ?? null;
        $planId = $session['metadata']['subscription_plan_id'] ?? null;
        $isRenewal = ($session['metadata']['is_renewal'] ?? '0') === '1';
        $paymentStatus = $session['payment_status'] ?? null;
        $paymentIntentId = $session['payment_intent'] ?? null;

        if (!$companyId || !$planId) {
            Log::warning('Missing metadata in checkout session', ['session' => $session]);
            return;
        }

        if ($paymentStatus !== 'paid') {
            Log::warning('Checkout session completed but payment not successful', [
                'session_id' => $session['id'],
                'payment_status' => $paymentStatus,
            ]);
            return;
        }

        $company = Company::find($companyId);
        $plan = SubscriptionPlan::find($planId);

        if (!$company || !$plan) {
            Log::warning('Company or plan not found', [
                'company_id' => $companyId,
                'plan_id' => $planId,
            ]);
            return;
        }

        // Activate or renew subscription
        $this->activateSubscription($company, $plan, $isRenewal, $paymentIntentId);
    }

    /**
     * Handle payment_intent.succeeded event (backup handler).
     */
    private function handlePaymentSucceeded(array $paymentIntent): void
    {
        // Payment succeeded - checkout.session.completed should handle this
        // This is just a backup in case checkout.session.completed doesn't fire
        Log::info('Payment intent succeeded', ['payment_intent_id' => $paymentIntent['id']]);
    }

    /**
     * Handle payment_intent.payment_failed event.
     */
    private function handlePaymentFailed(array $paymentIntent): void
    {
        Log::warning('Payment failed', [
            'payment_intent_id' => $paymentIntent['id'],
            'error' => $paymentIntent['last_payment_error'] ?? null,
        ]);
    }

    /**
     * Activate or renew subscription after successful payment.
     */
    private function activateSubscription(Company $company, SubscriptionPlan $plan, bool $isRenewal = false, ?string $paymentIntentId = null): void
    {
        $now = now();
        $periodEnd = match($plan->interval) {
            'month' => $now->copy()->addMonth(),
            'year' => $now->copy()->addYear(),
            default => $now->copy()->addMonth(),
        };

        if ($isRenewal) {
            // Renew existing subscription
            $subscription = $company->subscription;
            if ($subscription) {
                $subscription->update([
                    'status' => 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $periodEnd,
                    'canceled_at' => null,
                    'cancel_at_period_end' => false,
                    'stripe_payment_intent_id' => $paymentIntentId,
                ]);
            } else {
                // Create new subscription if it doesn't exist
                $subscription = Subscription::create([
                    'company_id' => $company->id,
                    'subscription_plan_id' => $plan->id,
                    'stripe_customer_id' => $company->stripe_customer_id,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'status' => 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $periodEnd,
                ]);
            }
        } else {
            // Create new subscription
            $subscription = Subscription::updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'subscription_plan_id' => $plan->id,
                    'stripe_customer_id' => $company->stripe_customer_id,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'status' => 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $periodEnd,
                ]
            );
        }

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

        Log::info('Subscription activated', [
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'is_renewal' => $isRenewal,
        ]);
    }
}

