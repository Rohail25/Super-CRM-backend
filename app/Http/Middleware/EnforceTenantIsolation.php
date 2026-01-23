<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantIsolation
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return $next($request);
        }

        // Super Admin bypasses all checks
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Enforce company isolation
        if ($request->has('company_id') || $request->route('company_id')) {
            $requestedCompanyId = $request->route('company_id') ?? $request->input('company_id');

            if ($requestedCompanyId != $user->company_id) {
                abort(403, 'Access denied: Company mismatch');
            }
        }

        // Enforce project access
        if ($request->has('project_id') || $request->route('project_id')) {
            $projectId = $request->route('project_id') ?? $request->input('project_id');

            $hasAccess = \App\Models\CompanyProjectAccess::where('company_id', $user->company_id)
                ->where('project_id', $projectId)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                abort(403, 'Access denied: No access to this project');
            }
        }

        // Add company_id to request for automatic scoping
        $request->merge(['enforced_company_id' => $user->company_id]);

        return $next($request);
    }
}
