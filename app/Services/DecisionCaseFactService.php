<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DecisionCaseFactService
{
    private const CASE_KEYS = [
        'case.jurisdiction',
        'case.immigration_status',
        'case.uk_driving_licence',
        'case.gambling_monthly',
        'case.gamstop_registered',
        'case.previous_iva',
        'case.previous_iva_failed',
        'case.previous_iva_failed_reason',
        'case.previous_bankruptcy',
        'case.joint_iva',
        'case.hmrc_majority',
        'case.hmrc_deduction_from_income',
        'case.benefits_only',
        'case.seiss_debt',
        'case.vat_debt',
        'case.tax_returns_up_to_date',
        'case.self_employed',
        'case.self_employed_trading_months',
        'case.self_employed_profitable',
        'case.self_employed_has_employees',
        'case.self_employed_partnership',
        'case.vulnerable_client',
        'evidence.bank_statement_months',
        'evidence.bank_statements_continuous',
        'evidence.income_proof_available',
        'evidence.uc_breakdown_available',
        'evidence.uc_journal_available',
        'evidence.credit_report_available',
        'partner.income_evidence_available',
        'partner.bank_statements_available',
        'partner.wage_slips_available',
        'partner.declaration_available',
        'property.is_homeowner',
        'property.value',
        'property.valuation_evidence',
        'property.mortgage_balance',
        'property.secured_loans_total',
        'property.ownership_percent',
        'property.joint_ownership',
        'property.owned_outright',
        'property.beneficial_interest_notes',
        'property.adaptations',
        'property.remortgage_restricted',
        'property.fixed_rate_end',
        'dmp.contractual_payments_total',
        'dmp.priority_debt_included',
        'dmp.council_tax_or_bailiff_included',
    ];

    private const DEBT_KEYS = [
        'debt.product_type',
        'debt.contractual_payment',
        'debt.payments_made',
        'debt.current_provider',
        'debt.repossessed_mortgage',
        'debt.keep_vehicle',
        'debt.attachment_of_earnings',
        'debt.attachment_of_benefit',
        'debt.is_priority',
        'debt.is_payday',
        'debt.is_council_tax',
        'debt.is_bailiff',
        'debt.current_property',
        'debt.is_joint',
        'debt.is_criminal',
        'debt.is_pcn',
        'debt.is_car_related',
        'debt.is_guarantor',
        'debt.creditor_location',
        'debt.last_spend_date',
        'debt.recent_spend_2_months',
        'debt.recent_spend_3_months',
        'debt.account_opened_date',
        'debt.finance_taken_date',
        'voting.representative_override',
    ];

    public function setLeadFact(Lead $lead, string $key, mixed $value, string $sourceType = 'operator', ?string $sourceDetail = null): array
    {
        if (!in_array($key, self::CASE_KEYS, true)) {
            throw new RuntimeException('Unsupported decision fact key: '.$key);
        }

        $where=['lead_id'=>$lead->id,'fact_key'=>$key];
        $payload=[
            'value_json'=>json_encode($value),
            'source_type'=>$sourceType,
            'source_detail'=>$sourceDetail,
            'recorded_at'=>now(),
            'updated_at'=>now(),
        ];
        if (DB::table('lead_decision_facts')->where($where)->exists()) DB::table('lead_decision_facts')->where($where)->update($payload);
        else DB::table('lead_decision_facts')->insert($where+$payload+['created_at'=>now()]);

        return ['success'=>true,'lead_id'=>$lead->id,'fact_key'=>$key,'value'=>$value,'source_type'=>$sourceType];
    }

    public function setDebtFact(Debt $debt, string $key, mixed $value, string $sourceType = 'operator', ?string $sourceDetail = null): array
    {
        if (!in_array($key, self::DEBT_KEYS, true)) {
            throw new RuntimeException('Unsupported debt decision fact key: '.$key);
        }

        $where=['debt_id'=>$debt->id,'fact_key'=>$key];
        $payload=[
            'value_json'=>json_encode($value),
            'source_type'=>$sourceType,
            'source_detail'=>$sourceDetail,
            'recorded_at'=>now(),
            'updated_at'=>now(),
        ];
        if (DB::table('debt_decision_facts')->where($where)->exists()) DB::table('debt_decision_facts')->where($where)->update($payload);
        else DB::table('debt_decision_facts')->insert($where+$payload+['created_at'=>now()]);

        return ['success'=>true,'debt_id'=>$debt->id,'lead_id'=>$debt->lead_id,'fact_key'=>$key,'value'=>$value,'source_type'=>$sourceType];
    }

    public function leadFacts(Lead $lead): array
    {
        return DB::table('lead_decision_facts')->where('lead_id',$lead->id)->get()
            ->mapWithKeys(fn($r)=>[$r->fact_key=>[
                'value'=>$this->decode($r->value_json),
                'source_type'=>$r->source_type,
                'source_detail'=>$r->source_detail,
                'recorded_at'=>$r->recorded_at,
            ]])->all();
    }

    public function leadValues(Lead $lead): array
    {
        return collect($this->leadFacts($lead))->mapWithKeys(fn($v,$k)=>[$k=>$v['value']])->all();
    }

    public function debtFacts(Debt $debt): array
    {
        return DB::table('debt_decision_facts')->where('debt_id',$debt->id)->get()
            ->mapWithKeys(fn($r)=>[$r->fact_key=>[
                'value'=>$this->decode($r->value_json),
                'source_type'=>$r->source_type,
                'source_detail'=>$r->source_detail,
                'recorded_at'=>$r->recorded_at,
            ]])->all();
    }

    public function debtValues(Debt $debt): array
    {
        return collect($this->debtFacts($debt))->mapWithKeys(fn($v,$k)=>[$k=>$v['value']])->all();
    }

    public function allForLead(Lead $lead): array
    {
        $lead->loadMissing('debts.creditor');
        return [
            'case_facts'=>$this->leadFacts($lead),
            'debt_facts'=>$lead->debts->map(fn($d)=>[
                'debt_id'=>$d->id,
                'creditor'=>$d->creditor?->name,
                'facts'=>$this->debtFacts($d),
            ])->all(),
        ];
    }

    public function recordIeAdjustment(Lead $lead, string $sectionKey, float $original, float $proposed, string $reason, ?int $ruleId = null, ?string $evidence = null, string $status = 'proposed'): array
    {
        if ($sectionKey === '' || $reason === '') throw new RuntimeException('I&E section and reason are required.');
        if (!in_array($status,['proposed','accepted','rejected','superseded'],true)) throw new RuntimeException('Invalid I&E adjustment status.');
        if ($ruleId && !DB::table('decision_rules')->where('id',$ruleId)->exists()) throw new RuntimeException('Decision rule not found.');

        $id=DB::table('lead_ie_adjustments')->insertGetId([
            'lead_id'=>$lead->id,
            'section_key'=>$sectionKey,
            'original_amount'=>round($original,2),
            'proposed_amount'=>round($proposed,2),
            'difference'=>round($proposed-$original,2),
            'reason'=>$reason,
            'rule_id'=>$ruleId,
            'evidence'=>$evidence,
            'status'=>$status,
            'actor'=>'jinx_agent',
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        return ['success'=>true,'adjustment_id'=>$id,'lead_id'=>$lead->id,'section_key'=>$sectionKey,'original_amount'=>round($original,2),'proposed_amount'=>round($proposed,2),'difference'=>round($proposed-$original,2),'status'=>$status];
    }

    public function ieAdjustments(Lead $lead): array
    {
        return DB::table('lead_ie_adjustments as a')
            ->leftJoin('decision_rules as r','r.id','=','a.rule_id')
            ->leftJoin('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('a.lead_id',$lead->id)
            ->orderBy('a.id')
            ->get(['a.*','s.name as rule_source_name','s.sheet as rule_source_sheet','s.location as rule_source_location'])
            ->map(fn($r)=>(array)$r)->all();
    }



    public function setIeAdjustmentStatus(Lead $lead, int $adjustmentId, string $status): array
    {
        if (!in_array($status,['proposed','accepted','rejected','superseded'],true)) {
            throw new RuntimeException('Invalid I&E adjustment status.');
        }
        $row=DB::table('lead_ie_adjustments')->where('lead_id',$lead->id)->where('id',$adjustmentId)->first();
        if (!$row) throw new RuntimeException('I&E adjustment not found for this case.');
        DB::table('lead_ie_adjustments')->where('id',$adjustmentId)->update(['status'=>$status,'updated_at'=>now()]);
        return ['success'=>true,'lead_id'=>$lead->id,'adjustment_id'=>$adjustmentId,'old_status'=>$row->status,'new_status'=>$status];
    }

    public function ieAdjustmentSummary(Lead $lead): array
    {
        $rows=$this->ieAdjustments($lead);
        $active=collect($rows)->filter(fn($r)=>in_array($r['status'],['proposed','accepted'],true));
        return [
            'entries'=>$rows,
            'active_entry_count'=>$active->count(),
            'net_monthly_expenditure_change'=>round((float)$active->sum(fn($r)=>(float)$r['difference']),2),
            'effect_on_disposable_income'=>round(-(float)$active->sum(fn($r)=>(float)$r['difference']),2),
            'instruction'=>'Positive expenditure differences reduce DI; negative expenditure differences increase DI. Keep natural/current figures distinct from proposed packaging adjustments.',
        ];
    }

    public static function caseKeys(): array { return self::CASE_KEYS; }
    public static function debtKeys(): array { return self::DEBT_KEYS; }

    private function decode(mixed $value): mixed
    {
        if ($value === null) return null;
        return json_decode((string)$value,true);
    }
}
