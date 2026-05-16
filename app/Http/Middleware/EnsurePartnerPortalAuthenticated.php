<?php

namespace App\Http\Middleware;

use App\Models\Partner;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerPortalAuthenticated
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $partnerId = $request->session()->get('partner_portal_partner_id');

        if (! is_numeric($partnerId)) {
            return redirect()->route('partner-portal.login');
        }

        $partner = Partner::query()
            ->whereKey($partnerId)
            ->where('portal_access_enabled', true)
            ->first();

        if (! $partner) {
            $request->session()->forget('partner_portal_partner_id');

            return redirect()->route('partner-portal.login');
        }

        $request->attributes->set('partner_portal_partner', $partner);

        return $next($request);
    }
}
