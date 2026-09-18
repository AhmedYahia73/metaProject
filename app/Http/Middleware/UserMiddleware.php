<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'user') {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden: User access required.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
