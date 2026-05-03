<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadPortalToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LeadPortalTokenService
{
    /**
     * @return array{token: string, portal_token: LeadPortalToken, reused: bool}
     */
    public function issueForLead(Lead $lead, ?string $createdIp = null): array
    {
        $usableTokens = $lead->portalTokens()
            ->whereNull('revoked_at')
            ->whereNull('completed_at')
            ->where(function ($query) {
                $query->where('status', LeadPortalToken::STATUS_PENDING)
                    ->orWhere(function ($activeQuery) {
                        $activeQuery->where('status', LeadPortalToken::STATUS_ACTIVE)
                            ->where('expires_at', '>', now());
                    });
            })
            ->latest('id')
            ->get();

        $usableToken = $usableTokens->first();

        if ($usableToken) {
            $cachedRawToken = Cache::get($this->rawTokenCacheKey($usableToken));
            if (is_string($cachedRawToken) && $cachedRawToken !== '') {
                return [
                    'token' => $cachedRawToken,
                    'portal_token' => $usableToken,
                    'reused' => true,
                ];
            }

            foreach ($usableTokens as $tokenToRevoke) {
                $this->revoke($tokenToRevoke);
            }
        }

        $rawToken = Str::random(64);

        $portalToken = LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $this->hashRawToken($rawToken),
            'status' => LeadPortalToken::STATUS_PENDING,
            'created_ip' => $createdIp,
        ]);

        Cache::put(
            $this->rawTokenCacheKey($portalToken),
            $rawToken,
            now()->addDays(35)
        );

        return [
            'token' => $rawToken,
            'portal_token' => $portalToken,
            'reused' => false,
        ];
    }

    public function resolveRawToken(string $rawToken, ?string $ip = null): ?LeadPortalToken
    {
        $token = LeadPortalToken::query()
            ->where('token_hash', $this->hashRawToken($rawToken))
            ->first();

        if ($token) {
            Cache::put($this->rawTokenCacheKey($token), $rawToken, now()->addDays(35));
        }

        if (! $token) {
            return null;
        }

        if ($token->revoked_at !== null || $token->completed_at !== null) {
            return null;
        }

        if ($token->status === LeadPortalToken::STATUS_EXPIRED) {
            return null;
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            $token->status = LeadPortalToken::STATUS_EXPIRED;
            $token->save();

            return null;
        }

        if ($token->status === LeadPortalToken::STATUS_PENDING) {
            $token->status = LeadPortalToken::STATUS_ACTIVE;
            $token->activated_at = now();
            $token->expires_at = now()->addDays(30);
        } elseif (
            $token->status !== LeadPortalToken::STATUS_ACTIVE
            || $token->expires_at === null
            || ! $token->expires_at->isFuture()
        ) {
            return null;
        }

        $token->last_used_at = now();
        $token->last_used_ip = $ip;
        $token->save();

        return $token->load('lead');
    }


    public function isTokenCurrentlyUsable(LeadPortalToken $token): bool
    {
        if ($token->revoked_at !== null || $token->completed_at !== null) {
            return false;
        }

        if ($token->status === LeadPortalToken::STATUS_EXPIRED) {
            return false;
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            $token->status = LeadPortalToken::STATUS_EXPIRED;
            $token->save();

            return false;
        }

        if ($token->status === LeadPortalToken::STATUS_PENDING) {
            return true;
        }

        return $token->status === LeadPortalToken::STATUS_ACTIVE
            && $token->expires_at !== null
            && $token->expires_at->isFuture();
    }

    public function getCachedRawToken(LeadPortalToken $token): ?string
    {
        $cachedRawToken = Cache::get($this->rawTokenCacheKey($token));

        return is_string($cachedRawToken) && $cachedRawToken !== '' ? $cachedRawToken : null;
    }

    public function revoke(
        LeadPortalToken $token,
        string $status = LeadPortalToken::STATUS_REVOKED
    ): LeadPortalToken {
        $token->status = $status;
        $token->revoked_at = now();
        $token->save();

        return $token;
    }

    public function complete(LeadPortalToken $token): LeadPortalToken
    {
        $token->status = LeadPortalToken::STATUS_COMPLETED;
        $token->completed_at = now();
        $token->revoked_at = now();
        $token->save();

        return $token;
    }

    public function expireOldTokens(): int
    {
        return LeadPortalToken::query()
            ->where('status', LeadPortalToken::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNull('revoked_at')
            ->whereNull('completed_at')
            ->update([
                'status' => LeadPortalToken::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);
    }

    public function hashRawToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function rawTokenCacheKey(LeadPortalToken $token): string
    {
        return 'lead_portal_token_raw:'.$token->id;
    }
}
