<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalWorkerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.local_worker.token');
        $providedToken = (string) $request->header('X-Local-Worker-Token', '');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
