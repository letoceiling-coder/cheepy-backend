<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = array_filter(explode(',', env('FRONTEND_URL', 'http://cheepy.loc')));

        $origin = $request->header('Origin');
        $allow = in_array($origin, $allowedOrigins) ? $origin : ($allowedOrigins[0] ?? '*');

        if ($request->isMethod('OPTIONS')) {
            return response('', 204)
                ->header('Access-Control-Allow-Origin', $allow)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With, Accept')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', $allow);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}
