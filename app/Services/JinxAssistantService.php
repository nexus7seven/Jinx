<?php

namespace App\Services;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class JinxAssistantService
{
    public function __construct(
        private readonly PartnerKnowledgeService $partnerKnowledge,
        private readonly DestinationSuitabilityService $destinationSuitability,
        private readonly ZebraIeCalculatorService $zebraIe,
    ) {
    }

    public function reply(AssistantConversation $conversation, string $message): array
    {
        $apiKey = (string) config('services.jinx_assistant.api_key');
        $model = (string) config('services.jinx_assistant.model');

        if ($apiKey === '' || $model === '') {
            throw new RuntimeException('Jinx Assistant is not configured. Set JINX_ASSISTANT_API_KEY and JINX_ASSISTANT_MODEL.');
        }

        $conversation->loadMissing('lead');
        $facts = $this->establishedFacts($conversation);
        $profile = $this->partnerKnowledge->profile($conversation->lead?->source, $facts);
        $knowledge = $this->knowledgeForProfile($profile);

        $history = $conversation->messages()
            ->latest('id')->limit(24)->get()->reverse()->values()
            ->map(fn (AssistantMessage $item) => ['role' => $item->role, 'content' => $item->content])
            ->all();

        $similarCases = $this->findSimilarCases($conversation, $message);
        $pendingKnowledge = data_get($conversation->metadata, 'pending_knowledge');
        $zebraApplies = ($profile['partner'] ?? null) === 'Zebra';
        $deterministicBefore = $zebraApplies ? $this->zebraIe->snapshot($facts) : [];
        $comparisonRequested = $this->destinationSuitability->shouldCompare($message);
        $destinationComparison = $comparisonRequested ? $this->destinationSuitability->context() : null;

        $instructions = <<<'PROMPT'
You are Jinx Assistant, an expert IVA case-packaging colleague used by trained case packagers inside the Jinx CRM.

The user is the case packager, not the IVA client. Be conversational and case-aware. Use information already known and ask only for genuinely missing facts.

KNOWLEDGE MODEL
Jinx uses scoped organisational knowledge. The current PARTNER_PROFILE identifies the partner and, where known, the IP destination.
Rule precedence is: IP-specific rule > partner-level rule > company-level rule. Never apply one partner's rule to another partner. Never apply one IP's criteria to another IP.

Current partner structure:
- Zebra: uses its own IP and Zebra codex/rules.
- Avondale: may submit to Lawson Fox, TIG, Assure or Anchorage Chambers. Each of those IPs can have distinct criteria. Avondale-wide rules apply to all four unless a more specific confirmed IP rule overrides them.

PARTNER_CODEX is the static authoritative baseline for the current partner. ACTIVE_SCOPED_KNOWLEDGE contains later confirmed organisational rules relevant to this exact case scope. A later confirmed scoped rule may supersede the static baseline when it clearly changes the same rule.

If a required partner/IP rule has not been supplied, do not invent it. State that the criterion is not yet in Jinx knowledge and ask for it only when needed.

CROSS-DESTINATION SUITABILITY
When DESTINATION_COMPARISON is present, assess the established case facts against every supplied destination independently.
Use only that destination's codex plus its active scoped knowledge. Do not transfer a rule from one destination to another.
For each destination use one of these statuses:
- FIT: all material known criteria supplied by Jinx are satisfied and no material required case fact is missing;
- NOT_FIT: at least one definite supplied criterion is failed;
- POSSIBLE_NEEDS_INFO: no definite failure is known, but material case facts required to decide are missing;
- INSUFFICIENT_RULES: Jinx does not hold enough destination criteria to make a safe assessment.

When asked where the case fits best, rank destinations by the cleanest confirmed fit. A FIT beats POSSIBLE_NEEDS_INFO. Never rank an INSUFFICIENT_RULES destination as the best fit. Explain the decisive reasons, definite blockers and missing facts. Do not claim an IP accepts a case merely because no rejecting rule was found.

TRAINING / NEW RULES
When the packager supplies a durable new rule or changed criterion, identify its correct scope:
- company: applies across all partners/IPs;
- partner: applies to all cases for a named partner;
- ip: applies only to one IP destination.

For Avondale, canonical IP names are: Lawson Fox, TIG, Assure, Anchorage Chambers.
If scope is ambiguous, ask what it applies to rather than guessing. Do not save case-specific facts as organisational knowledge.
Do not silently save a rule. Return a proposed_knowledge item and ask the packager to confirm it. On a later clear confirmation, set confirm_pending_knowledge=true.

For a new Zebra I&E, if calculation.target_di is not established, the first I&E question must be exactly: "What is the target DI?"
Maintain ESTABLISHED_FACTS. Every answer, including negative answers, becomes a fact. Never re-ask an established fact unless it changes, conflicts, or is genuinely insufficient. Return new/changed facts in fact_updates using stable dot-notated keys.

For Avondale I&E, PARTNER_CODEX currently requires the relevant SFS-controlled sections to be set to 65% of the applicable SFS maximum. Do not substitute Zebra-specific eligibility or packaging rules into an Avondale case.

DETERMINISTIC_IE contains calculations made by Jinx code. Treat those values as authoritative and do not recalculate them differently.

When referencing a prior case, describe only the useful similarity and avoid unnecessary personal information.

Return ONLY valid JSON using this exact shape:
{
  "reply": "natural-language reply to the packager",
  "fact_updates": {},
  "suitability_assessment": null OR {
    "best_fit": null OR "destination name",
    "best_fit_reason": "short explanation",
    "destinations": [
      {
        "destination": "destination name",
        "status": "FIT|NOT_FIT|POSSIBLE_NEEDS_INFO|INSUFFICIENT_RULES",
        "reasons": ["reason"],
        "missing": ["missing fact"]
      }
    ]
  },
  "proposed_knowledge": null OR {
    "scope": "company|partner|ip",
    "scope_key": null OR "canonical partner/IP identifier",
    "category": "short category",
    "title": "short rule title",
    "content": "the durable rule to remember"
  },
  "confirm_pending_knowledge": false,
  "case_summary": "brief rolling summary useful for similar-case retrieval"
}
PROMPT;

        $context = [
            'CURRENT_LEAD' => $this->leadContext($conversation),
            'PARTNER_PROFILE' => $profile,
            'PARTNER_CODEX' => $profile['partner_codex'] ?? null,
            'ACTIVE_SCOPED_KNOWLEDGE' => $knowledge->values()->toArray(),
            'DESTINATION_COMPARISON' => $destinationComparison,
            'ESTABLISHED_FACTS' => $facts,
            'DETERMINISTIC_IE' => $deterministicBefore,
            'PENDING_KNOWLEDGE_PROPOSAL' => $pendingKnowledge,
            'POTENTIALLY_SIMILAR_PRIOR_CASES' => $similarCases,
            'CONVERSATION_HISTORY' => $history,
            'LATEST_PACKAGER_MESSAGE' => $message,
        ];

        $response = Http::timeout(60)
            ->withToken($apiKey)->acceptJson()
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'instructions' => $instructions,
                'input' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'max_output_tokens' => $comparisonRequested ? 2600 : 1800,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Assistant provider error: '.$response->status().' '.$response->body());
        }

        $text = $this->extractOutputText($response->json());
        $decoded = json_decode($this->stripCodeFence($text), true);
        if (! is_array($decoded) || ! isset($decoded['reply'])) {
            throw new RuntimeException('Assistant returned an invalid response format.');
        }

        $factUpdates = $this->normaliseFactUpdates($decoded['fact_updates'] ?? []);
        $factsAfter = array_replace($facts, $factUpdates);
        $deterministicAfter = $zebraApplies ? $this->zebraIe->snapshot($factsAfter) : [];

        return [
            'reply' => trim((string) $decoded['reply']),
            'fact_updates' => $factUpdates,
            'deterministic_ie' => $deterministicAfter,
            'suitability_assessment' => $this->normaliseSuitabilityAssessment($decoded['suitability_assessment'] ?? null),
            'proposed_knowledge' => is_array($decoded['proposed_knowledge'] ?? null)
                ? $this->normaliseKnowledgeProposal($decoded['proposed_knowledge']) : null,
            'confirm_pending_knowledge' => (bool) ($decoded['confirm_pending_knowledge'] ?? false),
            'case_summary' => trim((string) ($decoded['case_summary'] ?? '')),
        ];
    }

    private function knowledgeForProfile(array $profile): Collection
    {
        $partner = Str::lower((string) ($profile['partner'] ?? ''));
        $ip = Str::lower((string) ($profile['ip'] ?? ''));

        return AssistantKnowledgeItem::query()
            ->active()->orderByDesc('updated_at')->limit(250)
            ->get(['id', 'scope', 'scope_key', 'category', 'title', 'content', 'updated_at'])
            ->filter(function (AssistantKnowledgeItem $item) use ($partner, $ip) {
                $scope = Str::lower((string) $item->scope);
                $key = Str::lower(trim((string) $item->scope_key));
                if ($scope === 'company') return true;
                if ($scope === 'partner') return $partner !== '' && $key === $partner;
                if ($scope === 'ip') return $ip !== '' && $key === $ip;
                return false;
            });
    }

    private function establishedFacts(AssistantConversation $conversation): array
    {
        $facts = data_get($conversation->metadata, 'established_facts', []);
        $facts = is_array($facts) ? $facts : [];
        $lead = $conversation->lead;
        if ($lead) {
            if ($lead->monthly_housing_cost !== null && ! array_key_exists('housing.rent_mortgage', $facts)) {
                $facts['housing.rent_mortgage'] = (float) $lead->monthly_housing_cost;
            }
            if ($lead->monthly_council_tax !== null && ! array_key_exists('housing.council_tax', $facts)) {
                $facts['housing.council_tax'] = (float) $lead->monthly_council_tax;
            }
        }
        return $facts;
    }

    private function leadContext(AssistantConversation $conversation): ?array
    {
        $lead = $conversation->lead;
        if (! $lead) return null;
        return [
            'id' => $lead->id,
            'name' => $lead->formattedName(),
            'wip_status' => $lead->wip_status,
            'source' => $lead->source,
            'employment_status' => $lead->employment_status,
            'monthly_income' => $lead->monthly_income,
            'monthly_housing_cost' => $lead->monthly_housing_cost,
            'monthly_council_tax' => $lead->monthly_council_tax,
            'monthly_utilities_cost' => $lead->monthly_utilities_cost,
            'monthly_food_travel_cost' => $lead->monthly_food_travel_cost,
            'estimated_total_debt' => $lead->estimated_total_debt,
            'financial_statement' => $lead->financial_statement,
        ];
    }

    private function findSimilarCases(AssistantConversation $conversation, string $message): array
    {
        $keywords = collect(preg_split('/[^a-zA-Z0-9]+/', Str::lower($message)) ?: [])
            ->filter(fn ($word) => strlen($word) >= 5)
            ->reject(fn ($word) => in_array($word, ['client','about','would','could','there','their','which','where','should'], true))
            ->unique()->take(6)->values();
        if ($keywords->isEmpty()) return [];

        $query = AssistantMessage::query()->where('role', 'user')
            ->where('conversation_id', '!=', $conversation->id)
            ->whereHas('conversation', fn ($q) => $q->whereNotNull('lead_id'));
        $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) $q->orWhere('content', 'like', '%'.$keyword.'%');
        });

        return $query->with('conversation:id,lead_id,summary')->latest('id')->limit(5)->get()
            ->unique('conversation_id')->take(3)
            ->map(fn (AssistantMessage $item) => [
                'lead_id' => $item->conversation?->lead_id,
                'conversation_id' => $item->conversation_id,
                'summary' => $item->conversation?->summary,
                'matching_message_excerpt' => Str::limit($item->content, 350),
            ])->values()->all();
    }

    private function normaliseFactUpdates(mixed $updates): array
    {
        if (! is_array($updates)) return [];
        $normalised = [];
        foreach (array_slice($updates, 0, 100, true) as $key => $value) {
            if (! is_string($key) || strlen($key) > 120) continue;
            if (is_scalar($value) || $value === null) $normalised[$key] = $value;
            elseif (is_array($value) && count($value) <= 30) $normalised[$key] = array_values($value);
        }
        return $normalised;
    }

    private function normaliseSuitabilityAssessment(mixed $assessment): ?array
    {
        if (! is_array($assessment)) {
            return null;
        }

        $allowedStatuses = ['FIT', 'NOT_FIT', 'POSSIBLE_NEEDS_INFO', 'INSUFFICIENT_RULES'];
        $destinations = [];

        foreach (array_slice($assessment['destinations'] ?? [], 0, 10) as $item) {
            if (! is_array($item)) continue;
            $status = strtoupper((string) ($item['status'] ?? ''));
            if (! in_array($status, $allowedStatuses, true)) {
                $status = 'POSSIBLE_NEEDS_INFO';
            }

            $destinations[] = [
                'destination' => Str::limit((string) ($item['destination'] ?? ''), 120, ''),
                'status' => $status,
                'reasons' => collect($item['reasons'] ?? [])->filter('is_string')->take(10)->values()->all(),
                'missing' => collect($item['missing'] ?? [])->filter('is_string')->take(10)->values()->all(),
            ];
        }

        return [
            'best_fit' => filled($assessment['best_fit'] ?? null)
                ? Str::limit((string) $assessment['best_fit'], 120, '')
                : null,
            'best_fit_reason' => Str::limit((string) ($assessment['best_fit_reason'] ?? ''), 1000, ''),
            'destinations' => $destinations,
        ];
    }

    private function normaliseKnowledgeProposal(array $proposal): array
    {
        $scope = in_array(($proposal['scope'] ?? null), ['company', 'partner', 'ip'], true)
            ? $proposal['scope'] : 'company';
        return [
            'scope' => $scope,
            'scope_key' => filled($proposal['scope_key'] ?? null) ? Str::limit(trim((string) $proposal['scope_key']), 120, '') : null,
            'category' => Str::limit((string) ($proposal['category'] ?? 'General'), 120, ''),
            'title' => Str::limit((string) ($proposal['title'] ?? 'Updated rule'), 255, ''),
            'content' => trim((string) ($proposal['content'] ?? '')),
        ];
    }

    private function extractOutputText(array $payload): string
    {
        if (is_string($payload['output_text'] ?? null) && $payload['output_text'] !== '') return $payload['output_text'];
        foreach (($payload['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) return $content['text'];
            }
        }
        throw new RuntimeException('Assistant provider returned no text output.');
    }

    private function stripCodeFence(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^```(?:json)?\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s*```$/', '', $value) ?? $value;
        return trim($value);
    }
}
