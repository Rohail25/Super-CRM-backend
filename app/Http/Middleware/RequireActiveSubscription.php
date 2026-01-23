<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Super admin bypasses subscription check
        if ($user && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Allow access to subscription-related endpoints
        $allowedPaths = [
            '/api/subscription',
            '/api/subscription-plans',
            '/api/subscription/checkout',
            '/api/subscription/success',
            '/api/subscription/cancel',
        ];

        $path = $request->path();
        foreach ($allowedPaths as $allowedPath) {
            if (str_starts_with($path, $allowedPath)) {
                return $next($request);
            }
        }

        if (!$user || !$user->company) {
            return response()->json([
                'message' => 'Company not found',
            ], 403);
        }

        $company = $user->company;

        // Check subscription status
        if ($company->subscription_status === 'approved') {
            // Company is approved but hasn't subscribed yet
            return response()->json([
                'message' => 'Subscription required',
                'subscription_required' => true,
                'company_status' => $company->subscription_status,
            ], 402); // 402 Payment Required
        }

        if (!$company->hasActiveSubscription()) {
            // Check if subscription is past due
            if ($company->subscription_status === 'past_due') {
                return response()->json([
                    'message' => 'Subscription payment failed. Please update your payment method.',
                    'subscription_status' => 'past_due',
                ], 402);
            }

            // Subscription canceled or inactive
            return response()->json([
                'message' => 'Active subscription required',
                'subscription_required' => true,
                'subscription_status' => $company->subscription_status,
            ], 402);
        }

        return $next($request);
    }
}

