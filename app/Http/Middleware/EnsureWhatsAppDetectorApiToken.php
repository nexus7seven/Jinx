<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWhatsAppDetectorApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('whatsapp_detector.api_token', '');
        $auth = (string) $request->header('Authorization', '');
        $token = '';
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            $token = $m[1];
        }

        if ($expected === '' || ! hash_equals($expected, $token)) {
            return response()->json([
                'status' => 'unauthorized',
                'event_id' => $request->input('event_id'),
                'match_status' => null,
                'is_after_flow_start' => null,
            ], 401);
        }

        return $next($request);
    }
}
