<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        // Ensure Laravel treats this as a JSON API request
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        return $response;
    }
}
