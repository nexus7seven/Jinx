<?php

namespace App\Services;

use App\Models\DebtDocument;
use App\Models\Lead;
use App\Models\LeadChecklistItem;

class LeadChecklistService
{
    private const DEFAULT_ITEMS = [
        'Photo ID',
        'Income Proof',
        'Bank Statement(s)',
    ];

    private const PROTECTED_STATUSES = [
        'Sale',
        'Lost Contact',
        'DEAD',
    ];

    private const REQUIRED_DEBT_SOURCES = [
        '3wc',
        'screenshot_pdf',
        'live_chat',
        'other',
    ];

    public function syncForLead(Lead $lead): void
    {
        $this->ensureDefaultItems($lead);
        $this->syncDebtEvidenceItems($lead);
        $this->syncLeadStatus($lead);
    }

    public function syncLeadStatus(Lead $lead): void
    {
        $lead->refresh();

        if (in_array($lead->wip_status, self::PROTECTED_STATUSES, true)) {
            return;
        }

        $total = LeadChecklistItem::where('lead_id', $lead->id)->count();

        $outstanding = LeadChecklistItem::where('lead_id', $lead->id)
            ->where('is_complete', false)
            ->count();

        if ($total === 0) {
            if (in_array($lead->wip_status, Lead::PRIORITY_WIP_STATUSES, true)) {
                return;
            }

            if ($lead->wip_status !== 'WIP') {
                $lead->update(['wip_status' => 'WIP']);
            }
            return;
        }

        if ($outstanding > 0) {
            if (in_array($lead->wip_status, Lead::PRIORITY_WIP_STATUSES, true)) {
                return;
            }

            if ($lead->wip_status !== 'Awaiting Docs') {
                $lead->update(['wip_status' => 'Awaiting Docs']);
            }
            return;
        }

        if ($lead->wip_status !== 'Ready to Draft') {
            $lead->update(['wip_status' => 'Ready to Draft']);
        }
    }

    private function ensureDefaultItems(Lead $lead): void
    {
        foreach (self::DEFAULT_ITEMS as $label) {
            $existing = LeadChecklistItem::where('lead_id', $lead->id)
                ->where('source_type', 'default')
                ->where('item_name', $label)
                ->first();

            if (!$existing) {
                LeadChecklistItem::create([
                    'lead_id' => $lead->id,
                    'item_name' => $label,
                    'is_complete' => false,
                    'source_type' => 'default',
                    'source_id' => null,
                    'is_system' => true,
                ]);
            }
        }
    }

    private function syncDebtEvidenceItems(Lead $lead): void
    {
        $documents = DebtDocument::with(['debt.creditor'])
            ->whereHas('debt', function ($query) use ($lead) {
                $query->where('lead_id', $lead->id);
            })
            ->get();

        foreach ($documents as $document) {
            $sourceExpected = $document->debt?->source_expected;

            $checklistItem = LeadChecklistItem::where('lead_id', $lead->id)
                ->where('source_type', 'debt_document')
                ->where('source_id', $document->id)
                ->first();

            if (!in_array($sourceExpected, self::REQUIRED_DEBT_SOURCES, true)) {
                if ($checklistItem) {
                    $checklistItem->delete();
                }

                if (!(bool) $document->is_complete) {
                    $document->update([
                        'is_complete' => true,
                    ]);
                }

                continue;
            }

            $itemName = $this->buildDebtEvidenceItemName($document);

            if (!(bool) $document->is_complete) {
                if (!$checklistItem) {
                    LeadChecklistItem::create([
                        'lead_id' => $lead->id,
                        'item_name' => $itemName,
                        'is_complete' => false,
                        'source_type' => 'debt_document',
                        'source_id' => $document->id,
                        'is_system' => true,
                    ]);
                } else {
                    $checklistItem->update([
                        'item_name' => $itemName,
                        'is_complete' => false,
                        'is_system' => true,
                    ]);
                }
            } else {
                if ($checklistItem) {
                    $checklistItem->update([
                        'item_name' => $itemName,
                        'is_complete' => true,
                        'is_system' => true,
                    ]);
                }
            }
        }
    }

    private function buildDebtEvidenceItemName(DebtDocument $document): string
    {
        $source = $document->debt?->source_expected ?? 'other';
        $creditorName = $document->debt?->creditor?->name ?? 'Debt Evidence';
        $reference = trim((string) ($document->debt?->reference ?? ''));

        $sourceLabel = match ($source) {
            '3wc' => '3WC',
            'screenshot_pdf' => 'Screenshot / PDF',
            'live_chat' => 'Live Chat',
            'other' => 'Other',
            default => 'Evidence',
        };

        if ($reference !== '') {
            return $sourceLabel . ': ' . $creditorName . ' - ' . $reference;
        }

        return $sourceLabel . ': ' . $creditorName;
    }
}