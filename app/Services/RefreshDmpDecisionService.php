<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RefreshDmpDecisionService
{
    public function __construct(private readonly DecisionCaseFactService $facts) {}

    public function evaluate(Lead $lead, ?array $ieSnapshot = null): array
    {
        $lead->loadMissing('debts.creditor');
        $case = $this->facts->leadValues($lead);
        $rules = $this->rules();

        $debtTotal = round((float)$lead->debts->sum('balance'),2);
        $lines = $lead->debts->count();
        $creditors = $lead->debts->pluck('creditor_id')->filter()->unique()->count();
        $di = data_get($ieSnapshot,'calculation.disposable_income');
        $jurisdiction = strtolower(trim((string)($case['case.jurisdiction'] ?? '')));

        $blockers=[];$actions=[];$unknowns=[];$excluded=[];$stipulations=[];

        if ($debtTotal < 2000) $blockers[]=$this->finding('Debt total is below the £2,000 Refresh minimum.','Minimum Debt Level');
        if ($lines < 2) $blockers[]=$this->finding('Refresh requires at least two debt lines/creditors.','2 or more creditors');
        if ($jurisdiction === '') $unknowns[]='case.jurisdiction';
        elseif (str_contains($jurisdiction,'scot')) $blockers[]=$this->finding('Refresh cannot accept Scottish cases.','Scottish cases');

        $priority = $this->bool($case['dmp.priority_debt_included'] ?? null);
        $councilBailiff = $this->bool($case['dmp.council_tax_or_bailiff_included'] ?? null);
        $debtFacts=[];$priorityDetected=($priority===true || $councilBailiff===true);
        foreach ($lead->debts as $debt) {
            $dv=$this->facts->debtValues($debt);
            $debtFacts[$debt->id]=$dv;
            $name=strtolower(trim((string)($debt->creditor?->name ?? '')));
            $product=strtolower(trim((string)($dv['debt.product_type'] ?? '')));
            if (($dv['debt.is_priority']??false) || ($dv['debt.is_council_tax']??false) || ($dv['debt.is_bailiff']??false)
                || str_contains($name,'council') || str_contains($name,'bailiff')
                || str_contains($product,'council tax') || str_contains($product,'bailiff')) {
                $priorityDetected=true;
            }
        }

        $requiredDi = $priorityDetected ? 150.0 : 100.0;
        if ($di === null) $unknowns[]='current disposable income';
        elseif ((float)$di <= $requiredDi) $blockers[]=$this->finding(
            'Disposable income is £'.number_format((float)$di,2).' and must be more than £'.number_format($requiredDi,2).'.',
            $requiredDi===150.0 ? 'Priority Debts DI' : 'Disposable income'
        );

        $contractualKnown=true;$contractualTotal=0.0;$includedContractual=0.0;
        foreach ($lead->debts as $debt) {
            $dv=$debtFacts[$debt->id] ?? [];
            $name=trim((string)($debt->creditor?->name ?? 'Unknown creditor'));
            $norm=$this->norm($name);
            $product=strtolower(trim((string)($dv['debt.product_type'] ?? '')));
            $currentProvider=$this->bool($dv['debt.current_provider'] ?? null);
            $paymentsMade=$this->num($dv['debt.payments_made'] ?? null);
            $contractual=$this->num($dv['debt.contractual_payment'] ?? null);
            $isExcluded=false;

            if ($contractual===null) $contractualKnown=false; else $contractualTotal += $contractual;

            $rejectStatus=$this->hardRejectCreditor($norm,$currentProvider,$dv);
            if ($rejectStatus === true) {
                $blockers[]=$this->finding($name.' is listed as a rejecting creditor for Refresh DMP.',$name);
            } elseif ($rejectStatus === null) {
                $unknowns[]='debt '.$debt->id.' current-provider/location fact needed for '.$name;
            }

            if ($product==='') $unknowns[]='debt '.$debt->id.' product_type';
            if (($dv['debt.is_pcn']??false) || ($dv['debt.is_car_related']??false) || ($dv['debt.is_criminal']??false) || $this->containsAny($product,['pcn','parking charge','criminal'])) {
                $blockers[]=$this->finding($name.' is recorded as a debt type Refresh says not to accept (PCN/car-related/criminal debt).','PCNs');
            }

            if (str_contains($product,'mortgage') && !array_key_exists('debt.repossessed_mortgage',$dv)) {
                $unknowns[]='debt '.$debt->id.' repossessed_mortgage';
            }
            if (str_contains($product,'rent arrears') && !array_key_exists('debt.current_property',$dv)) {
                $unknowns[]='debt '.$debt->id.' current_property';
            }
            if ($this->containsAny($product,['hire purchase','hp','pcp']) && !array_key_exists('debt.keep_vehicle',$dv)) {
                $unknowns[]='debt '.$debt->id.' keep_vehicle';
            }

            if ($this->excludedDebt($name,$product,$dv)) {
                $excluded[]=[
                    'debt_id'=>$debt->id,'creditor'=>$name,'balance'=>(float)$debt->balance,
                    'reason'=>$this->excludedReason($name,$product,$dv),
                ];
                $isExcluded=true;
            }

            if (!$isExcluded && $contractual!==null) $includedContractual += $contractual;

            if ($this->threePaymentCreditor($norm) && ($paymentsMade===null || $paymentsMade<3)) {
                $stipulations[]=['debt_id'=>$debt->id,'creditor'=>$name,'requirement'=>'3 contractual payments required before inclusion','payments_made'=>$paymentsMade];
            }
            if (($dv['debt.is_payday']??false) && ($paymentsMade===null || $paymentsMade<1)) {
                $stipulations[]=['debt_id'=>$debt->id,'creditor'=>$name,'requirement'=>'Ideally one payment before inclusion','payments_made'=>$paymentsMade];
            }
            if ($this->containsAny($norm,['everydayloans','edl'])) {
                $stipulations[]=['debt_id'=>$debt->id,'creditor'=>$name,'requirement'=>'Payment rate: 12.5% of contractual payment or minimum £15'];
            }
            if ($this->containsAny($norm,['onestopmoneyshop'])) {
                $stipulations[]=['debt_id'=>$debt->id,'creditor'=>$name,'requirement'=>'Payment rate: 50% of contractual payment'];
            }
        }

        if (!$contractualKnown) $unknowns[]='one or more debt.contractual_payment facts';
        else {
            $minimumPayment=round($includedContractual*0.25,2);
            if ($di!==null && (float)$di < $minimumPayment) {
                $blockers[]=$this->finding(
                    'Available DI £'.number_format((float)$di,2).' is below 25% of included contractual payments (£'.number_format($minimumPayment,2).').',
                    '25% of contractual payments'
                );
            }
        }

        if ($excluded) $actions[]='Keep the listed excluded debt types outside the DMP and account for any ongoing payment appropriately.';
        if ($stipulations) $actions[]='Resolve creditor-specific Refresh stipulations before inclusion.';

        $status = $blockers ? 'BLOCKED' : ($unknowns ? 'UNKNOWN' : (($actions||$stipulations||$excluded) ? 'FIT_WITH_ACTIONS' : 'CLEAR_FIT'));

        return [
            'solution'=>'Refresh DMP',
            'status'=>$status,
            'debt_total'=>$debtTotal,
            'debt_lines'=>$lines,
            'distinct_creditors'=>$creditors,
            'disposable_income'=>$di,
            'required_disposable_income_over'=>$requiredDi,
            'contractual_payments_total'=>$contractualKnown?round($contractualTotal,2):null,
            'included_contractual_payments_total'=>$contractualKnown?round($includedContractual,2):null,
            'minimum_dmp_payment_25_percent'=>$contractualKnown?round($includedContractual*0.25,2):null,
            'blockers'=>$blockers,
            'unknowns'=>array_values(array_unique($unknowns)),
            'excluded_debts'=>$excluded,
            'creditor_stipulations'=>$stipulations,
            'actions'=>$actions,
            'source_rules'=>$rules,
            'instruction'=>'This is a Refresh-specific DMP fallback assessment based only on the supplied September 2026 pack and recorded case/debt facts. UNKNOWN means a required fact has not been recorded; do not invent it.',
        ];
    }

    private function rules(): array
    {
        return DB::table('decision_rules as r')->join('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.partner_key','refresh_dmp')->where('r.is_active',true)
            ->get(['r.id','r.category','r.requirement_text','r.severity','s.name as source_name','s.sheet','s.location'])
            ->map(fn($r)=>(array)$r)->all();
    }

    private function finding(string $message,string $needle): array
    {
        $rule=DB::table('decision_rules as r')->join('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.partner_key','refresh_dmp')->where('r.requirement_text','like','%'.$needle.'%')
            ->select('r.id','r.requirement_text','s.name as source_name','s.sheet','s.location')->first();
        return ['message'=>$message,'rule'=>$rule?(array)$rule:null];
    }

    private function hardRejectCreditor(string $n, ?bool $current, array $facts): ?bool
    {
        foreach ([
            'britishgas','tescomobile','vodafone','talktalk','raylo','commsave',
            'londonboroughofredbridge','reventus','ambervalley','basildoncouncil',
            'boroughofbarrowinfurness','eastlyndsay','kingslynnandwestnorfolk',
            'sandwell','southtyneside','swalecouncil','whyteandco','londonboroughnewham',
            'graveshamcouncil','ealingcouncil',
        ] as $x) if (str_contains($n,$x)) return true;

        if (str_contains($n,'eon') || str_contains($n,'scottishpower')) {
            return $current;
        }

        if (str_contains($n,'creditunion')) {
            $location=strtolower(trim((string)($facts['debt.creditor_location']??'')));
            if ($location==='') return null;
            return str_contains($location,'northern ireland');
        }

        return false;
    }

    private function excludedDebt(string $name,string $product,array $f): bool
    {
        $n=$this->norm($name);
        if (str_contains($product,'mortgage')) return ($f['debt.repossessed_mortgage']??null) === false;
        if (str_contains($product,'rent arrears')) return ($f['debt.current_property']??null) === true;
        if (($this->containsAny($product,['hire purchase','hp','pcp'])) && ($f['debt.keep_vehicle']??false)) return true;
        if ($f['debt.attachment_of_earnings']??false) return true;
        if ($f['debt.attachment_of_benefit']??false) return true;
        if ($f['debt.is_guarantor']??false) return true;
        return $this->containsAny($product,['student loan','csa','child support','guarantor','hmcts','tv licence'])
            || str_contains($n,'dvla') || str_contains($n,'hmcts');
    }

    private function excludedReason(string $name,string $product,array $f): string
    {
        if (str_contains($product,'mortgage')) return 'Mortgage cannot be included unless the house is already repossessed.';
        if (str_contains($product,'rent arrears')) return 'Rent arrears for the current property cannot be included.';
        if ($this->containsAny($product,['hire purchase','hp','pcp'])) return 'Vehicle finance being retained is treated as essential expenditure rather than DMP debt.';
        if ($f['debt.attachment_of_earnings']??false) return 'DMP cannot reverse an Attachment of Earnings.';
        if ($f['debt.attachment_of_benefit']??false) return 'DMP cannot reverse an Attachment of Benefit.';
        return 'Refresh pack lists this debt type as not to be included in the DMP.';
    }

    private function threePaymentCreditor(string $n): bool
    {
        return $this->containsAny($n,['everydayloans','edl','bamboo','savvy','ticktock','abound','cashfloat','loansbymal']);
    }

    private function norm(string $v): string
    {
        return preg_replace('/[^a-z0-9]+/','',Str::ascii(Str::lower($v))) ?? '';
    }
    private function containsAny(string $value,array $needles): bool
    {
        foreach($needles as $n) if(str_contains($value,$n)) return true;
        return false;
    }
    private function bool(mixed $v): ?bool
    {
        if ($v===null) return null;
        if (is_bool($v)) return $v;
        return filter_var($v,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    }
    private function num(mixed $v): ?float { return is_numeric($v)?(float)$v:null; }
}
