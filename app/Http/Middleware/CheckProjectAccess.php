<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckProjectAccess
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
            abort(401, 'Unauthenticated');
        }

        // Super Admin bypasses all checks
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $projectId = $request->route('project') ?? $request->route('project_id') ?? $request->input('project_id');

        if ($projectId) {
            $hasAccess = \App\Models\CompanyProjectAccess::where('company_id', $user->company_id)
                ->where('project_id', $projectId)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                abort(403, 'Access denied: No access to this project');
            }
        }

        return $next($request);
    }
}
