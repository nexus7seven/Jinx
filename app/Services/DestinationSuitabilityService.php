<?php

namespace App\Services;

use App\Models\AssistantKnowledgeItem;
use Illuminate\Support\Str;

class DestinationSuitabilityService
{
    public function __construct(
        private readonly PartnerKnowledgeService $partnerKnowledge,
    ) {
    }

    public function shouldCompare(string $message): bool
    {
        $text = Str::lower(trim($message));

        foreach ([
            'where does this case fit',
            'where does this fit',
            'where would this fit',
            'where does the case fit',
            'fit best',
            'best fit',
            'which ip',
            'which partner',
            'which destination',
            'where should this go',
            'where can this go',
            'compare criteria',
            'compare destinations',
            'suitable for zebra',
            'suitable for lawson',
            'suitable for tig',
            'suitable for assure',
            'suitable for anchorage',
        ] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public function context(): array
    {
        return collect($this->partnerKnowledge->comparisonCatalogue())
            ->map(function (array $destination) {
                $partner = Str::lower((string) ($destination['partner'] ?? ''));
                $ip = Str::lower((string) ($destination['ip'] ?? ''));

                $knowledge = AssistantKnowledgeItem::query()
                    ->active()
                    ->orderByDesc('updated_at')
                    ->limit(250)
                    ->get(['id', 'scope', 'scope_key', 'category', 'title', 'content', 'updated_at'])
                    ->filter(function (AssistantKnowledgeItem $item) use ($partner, $ip) {
                        $scope = Str::lower((string) $item->scope);
                        $key = Str::lower(trim((string) $item->scope_key));

                        if ($scope === 'company') {
                            return true;
                        }
                        if ($scope === 'partner') {
                            return $partner !== '' && $key === $partner;
                        }
                        if ($scope === 'ip') {
                            return $ip !== '' && $key === $ip;
                        }

                        return false;
                    })
                    ->values()
                    ->toArray();

                $destination['active_scoped_knowledge'] = $knowledge;

                return $destination;
            })
            ->values()
            ->all();
    }
}
