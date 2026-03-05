<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', ['https://siteaacess.store', 'http://cheepy.loc']);
        $allowedOrigins = array_values(array_unique(array_map('trim', $allowedOrigins)));

        $origin = $request->header('Origin');
        $originTrimmed = $origin ? rtrim($origin, '/') : '';
        $allow = $allowedOrigins[0] ?? '*';
        foreach ($allowedOrigins as $o) {
            $ot = rtrim($o, '/');
            if ($origin === $o || ($originTrimmed && $originTrimmed === $ot)) {
                $allow = $origin;
                break;
            }
        }

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
