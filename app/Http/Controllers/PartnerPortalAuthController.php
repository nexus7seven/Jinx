<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PartnerPortalAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('partner.portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
$validated = $request->validate([
    'email' => ['required', 'string'],
    'password' => ['required', 'string'],
]);
        $partner = Partner::query()
            ->where('portal_email', strtolower(trim($validated['email'])))
            ->where('portal_access_enabled', true)
            ->first();

        if (! $partner || ! is_string($partner->portal_password) || ! Hash::check($validated['password'], $partner->portal_password)) {
            return back()->withErrors(['email' => 'Invalid credentials.'])->withInput();
        }

        $request->session()->regenerate();
        $request->session()->put('partner_portal_partner_id', $partner->id);

        return redirect()->route('partner-portal.leads');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('partner_portal_partner_id');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('partner-portal.login');
    }

    public function leads(Request $request): View
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner_portal_partner');

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'submitted_by' => ['nullable', 'string'],
        ]);

        $selectedStatus = trim((string) ($validated['status'] ?? ''));
        $selectedSubmittedBy = trim((string) ($validated['submitted_by'] ?? ''));

        $query = Lead::query()
            ->where('source', $partner->name)
            ->orderByDesc('created_at');

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        if ($selectedStatus !== '') {
            $query->where('wip_status', $selectedStatus);
        }

        if ($selectedSubmittedBy !== '') {
            $query->where('submitted_by_vicidial_user', $selectedSubmittedBy);
        }

        $statusOptions = Lead::query()
            ->where('source', $partner->name)
            ->whereNotNull('wip_status')
            ->where('wip_status', '!=', '')
            ->distinct()
            ->orderBy('wip_status')
            ->pluck('wip_status');

        $submittedByOptions = Lead::query()
            ->where('source', $partner->name)
            ->whereNotNull('submitted_by_vicidial_user')
            ->where('submitted_by_vicidial_user', '!=', '')
            ->distinct()
            ->orderBy('submitted_by_vicidial_user')
            ->pluck('submitted_by_vicidial_user');

        $leads = $query->get([
            'id',
            'created_at',
            'first_name',
            'last_name',
            'phone_number',
            'wip_status',
            'submitted_by_vicidial_user',
            'lead_feedback',
            'case_notes',
        ]);

        return view('partner.portal.leads', [
            'partner' => $partner,
            'leads' => $leads,
            'from' => $validated['from'] ?? '',
            'to' => $validated['to'] ?? '',
            'selectedStatus' => $selectedStatus,
            'selectedSubmittedBy' => $selectedSubmittedBy,
            'statusOptions' => $statusOptions,
            'submittedByOptions' => $submittedByOptions,
            'statusFieldUsed' => 'wip_status',
        ]);
    }
}
