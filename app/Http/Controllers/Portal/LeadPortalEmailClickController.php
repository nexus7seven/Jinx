<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\LeadPortalEmailClick;
use App\Models\LeadPortalSnapshot;
use Illuminate\Http\Request;

class LeadPortalEmailClickController extends Controller
{
    public function redirect(Request $request, LeadPortalSnapshot $snapshot, string $type)
    {
        if ($type !== 'whatsapp') {
            abort(404);
        }

        $destinationUrl = config('services.portal.whatsapp_url');
        if (blank($destinationUrl)) {
            abort(404);
        }

        LeadPortalEmailClick::create([
            'lead_id' => $snapshot->lead_id,
            'lead_portal_snapshot_id' => $snapshot->id,
            'click_type' => $type,
            'destination_url' => $destinationUrl,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'clicked_at' => now(),
            'raw_context_json' => [
                'referer' => $request->headers->get('referer'),
                'request_query' => $request->query(),
            ],
        ]);

        return redirect()->away($destinationUrl);
    }
}
