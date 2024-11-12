<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    protected function redirectTo($request)
    {
        // Check if the request is expecting a JSON response (API route)
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'You are not authenticated. Please provide a valid token.'
            ], 401);
        }

        // For other types of requests (e.g., web), return a redirect route (you can customize this if needed)
        return route('login');
    }
}

