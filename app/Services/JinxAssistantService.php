<?php

namespace App\Services;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class JinxAssistantService
{
    public function __construct(
        private readonly ZebraCodexService $zebraCodex,
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

        $knowledge = AssistantKnowledgeItem::query()
            ->active()
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'scope', 'scope_key', 'category', 'title', 'content', 'updated_at']);

        $history = $conversation->messages()
            ->latest('id')
            ->limit(24)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AssistantMessage $item) => [
                'role' => $item->role,
                'content' => $item->content,
            ])
            ->all();

        $similarCases = $this->findSimilarCases($conversation, $message);
        $pendingKnowledge = data_get($conversation->metadata, 'pending_knowledge');
        $facts = $this->establishedFacts($conversation);
        $zebraApplies = $this->zebraCodex->appliesTo($conversation->lead?->source);
        $codex = $zebraApplies ? $this->zebraCodex->load() : null;
        $deterministicBefore = $zebraApplies ? $this->zebraIe->snapshot($facts) : [];

        $instructions = <<<'PROMPT'
You are Jinx Assistant, an expert IVA case-packaging colleague used by trained case packagers inside the Jinx CRM.

Your job is conversational, not form-like. Understand what the packager has already told you and only ask for information that is actually missing. You can explain IVA packaging, reason about case information, help with income and expenditure, and reference relevant previous Jinx Assistant cases when useful.

For Zebra cases, ZEBRA_CODEX is the authoritative baseline. Follow it exactly. Do not silently fill gaps with general IVA knowledge. If the codex says RULE_REQUIRED or CALCULATOR_REQUIRED, preserve that limitation. Active ORGANISATIONAL_KNOWLEDGE explicitly scoped to Zebra may supersede the static codex when it clearly represents a later confirmed rule change.

Maintain ESTABLISHED_FACTS. Every answer, including negative answers, becomes a fact. Never re-ask an established fact unless the packager changes it, contradicts it, or the fact is genuinely insufficient for a supplied rule. When the latest message establishes or changes facts, return them in fact_updates using stable dot-notated keys.

For a new Zebra I&E, if calculation.target_di is not already established, the first I&E question must be exactly: "What is the target DI?" Do not begin the normal income/expenditure flow before that. Ask one question at a time except for the codex-approved grouped secondary income/benefit screen. Speak to the case packager, not the client.

Useful fact keys include calculation.target_di, income.client_salary, income.partner_salary, income.universal_credit, income.pip_dla, income.esa, income.carers_allowance, income.maintenance_received, income.pension, income.student, income.foster_guardianship, household.partner_exists, household.children_count, household.children_ages, income.child_benefit_qualifying_children, housing.rent_mortgage, housing.council_tax, transport.client.mode, transport.client.car_insurance, transport.partner.mode, transport.partner.car_insurance, other.childcare, other.maintenance_paid. Use booleans for yes/no facts, numbers for money/counts, and arrays for child ages.

DETERMINISTIC_IE is produced by Jinx code from the supplied Zebra formulas. Treat those calculated values as authoritative and do not recalculate them differently. It currently provides TV licence, utility ranges, SFS household ranges, Child Benefit when qualifying-child count is established, transport ranges/defaults, and required expenditure when total income and target DI are known.

Treat supplied ORGANISATIONAL_KNOWLEDGE as the current source of truth for company, partner and IP criteria outside the codex. If a packager states a new general rule or changed criterion, do not silently overwrite knowledge. Propose a concise knowledge item and ask for confirmation. On the next turn, if the packager clearly confirms, set confirm_pending_knowledge=true. If they reject or modify it, do not confirm it and create a corrected proposal if appropriate.

Case-specific facts are not organisational rules. Never propose saving names, addresses, account details, health information or other client-specific facts as organisational knowledge.

For calculations, be precise and show the important result clearly. Never invent SFS limits, benefit awards, conversion methods, partner/IP criteria or missing deterministic rules.

When referencing a prior case, describe the useful similarity without exposing unnecessary personal information. Refer to it by Jinx lead/case ID when available.

Return ONLY valid JSON using this exact shape:
{
  "reply": "natural-language reply to the packager",
  "fact_updates": {},
  "proposed_knowledge": null OR {
    "scope": "company|partner|ip",
    "scope_key": null OR "partner/IP identifier if known",
    "category": "short category",
    "title": "short rule title",
    "content": "the durable rule to remember"
  },
  "confirm_pending_knowledge": false,
  "case_summary": "brief rolling summary of the case/conversation useful for future similar-case retrieval"
}
PROMPT;

        $context = [
            'CURRENT_LEAD' => $this->leadContext($conversation),
            'ESTABLISHED_FACTS' => $facts,
            'DETERMINISTIC_IE' => $deterministicBefore,
            'ZEBRA_CODEX' => $codex,
            'ORGANISATIONAL_KNOWLEDGE' => $knowledge->toArray(),
            'PENDING_KNOWLEDGE_PROPOSAL' => $pendingKnowledge,
            'POTENTIALLY_SIMILAR_PRIOR_CASES' => $similarCases,
            'CONVERSATION_HISTORY' => $history,
            'LATEST_PACKAGER_MESSAGE' => $message,
        ];

        $response = Http::timeout(60)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'instructions' => $instructions,
                'input' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'max_output_tokens' => 1800,
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
            'proposed_knowledge' => is_array($decoded['proposed_knowledge'] ?? null)
                ? $this->normaliseKnowledgeProposal($decoded['proposed_knowledge'])
                : null,
            'confirm_pending_knowledge' => (bool) ($decoded['confirm_pending_knowledge'] ?? false),
            'case_summary' => trim((string) ($decoded['case_summary'] ?? '')),
        ];
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
        if (! $lead) {
            return null;
        }

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
            ->reject(fn ($word) => in_array($word, ['client', 'about', 'would', 'could', 'there', 'their', 'which', 'where', 'should'], true))
            ->unique()
            ->take(6)
            ->values();

        if ($keywords->isEmpty()) {
            return [];
        }

        $query = AssistantMessage::query()
            ->where('role', 'user')
            ->where('conversation_id', '!=', $conversation->id)
            ->whereHas('conversation', fn ($q) => $q->whereNotNull('lead_id'));

        $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('content', 'like', '%'.$keyword.'%');
            }
        });

        return $query
            ->with('conversation:id,lead_id,summary')
            ->latest('id')
            ->limit(5)
            ->get()
            ->unique('conversation_id')
            ->take(3)
            ->map(fn (AssistantMessage $item) => [
                'lead_id' => $item->conversation?->lead_id,
                'conversation_id' => $item->conversation_id,
                'summary' => $item->conversation?->summary,
                'matching_message_excerpt' => Str::limit($item->content, 350),
            ])
            ->values()
            ->all();
    }

    private function normaliseFactUpdates(mixed $updates): array
    {
        if (! is_array($updates)) {
            return [];
        }

        $normalised = [];
        foreach (array_slice($updates, 0, 100, true) as $key => $value) {
            if (! is_string($key) || strlen($key) > 120) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $normalised[$key] = $value;
            } elseif (is_array($value) && count($value) <= 30) {
                $normalised[$key] = array_values($value);
            }
        }

        return $normalised;
    }

    private function normaliseKnowledgeProposal(array $proposal): array
    {
        $scope = in_array(($proposal['scope'] ?? null), ['company', 'partner', 'ip'], true)
            ? $proposal['scope']
            : 'company';

        return [
            'scope' => $scope,
            'scope_key' => filled($proposal['scope_key'] ?? null) ? Str::limit((string) $proposal['scope_key'], 120, '') : null,
            'category' => Str::limit((string) ($proposal['category'] ?? 'General'), 120, ''),
            'title' => Str::limit((string) ($proposal['title'] ?? 'Updated rule'), 255, ''),
            'content' => trim((string) ($proposal['content'] ?? '')),
        ];
    }

    private function extractOutputText(array $payload): string
    {
        if (is_string($payload['output_text'] ?? null) && $payload['output_text'] !== '') {
            return $payload['output_text'];
        }

        foreach (($payload['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
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
