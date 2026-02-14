<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures CORS headers are on every response (including errors).
 * Allows requests from any origin so app.leox24.com and other frontends can call the API.
 */
class AddCorsHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // CORS preflight (OPTIONS): return 204 with CORS immediately so it works even when no route matches
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)
                ->withHeaders($this->corsHeaders($request));
        }

        $response = $next($request);

        // Add CORS headers if not already present
        if (!$response->headers->has('Access-Control-Allow-Origin')) {
            foreach ($this->corsHeaders($request) as $key => $value) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }

    private function corsHeaders(Request $request): array
    {
        $origin = $request->header('Origin');
        
        // Allow specific origin or all origins
        $allowedOrigins = [
            'https://app.leox24.com',
            'http://localhost:3000',
            'http://localhost:5173',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:5173',
        ];
        
        // If origin is in allowed list, use it; otherwise allow all for development
        $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

        $headers = [
            'Access-Control-Allow-Origin'      => $allowOrigin,
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-XSRF-TOKEN',
            'Access-Control-Expose-Headers'    => 'Content-Range, X-Content-Range, Content-Length',
            'Access-Control-Max-Age'           => '86400',
            'Cross-Origin-Resource-Policy'     => 'cross-origin',
            'Cross-Origin-Embedder-Policy'     => 'unsafe-none',
        ];

        if ($origin && in_array($origin, $allowedOrigins)) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }
}
