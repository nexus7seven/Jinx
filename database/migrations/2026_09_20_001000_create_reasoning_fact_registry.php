<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_fact_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('fact_key', 120)->unique();
            $table->string('scope', 20);
            $table->string('label', 160);
            $table->string('data_type', 30);
            $table->string('storage_type', 40)->default('lead_decision_fact');
            $table->string('storage_key', 160)->nullable();
            $table->text('question')->nullable();
            $table->json('source_hints')->nullable();
            $table->json('applicability')->nullable();
            $table->boolean('reasoning_required')->default(false);
            $table->unsignedInteger('question_priority')->default(1000);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['scope','is_active','reasoning_required'], 'decision_fact_scope_active_required_idx');
        });

        Schema::create('decision_dynamic_rules', function (Blueprint $table) {
            $table->id();
            $table->string('destination_key', 60)->nullable();
            $table->string('title', 255);
            $table->string('fact_key', 120);
            $table->string('operator', 30);
            $table->json('expected_value_json')->nullable();
            $table->string('result_status', 50);
            $table->text('reason');
            $table->json('applicability')->nullable();
            $table->string('source_type', 40)->default('operator_rule');
            $table->text('source_detail')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['destination_key','is_active'], 'decision_dynamic_route_active_idx');
            $table->index(['fact_key','is_active'], 'decision_dynamic_fact_active_idx');
        });

        $now = now();
        $rows = [
            // Core case skeleton. Existing CRM/I&E values are referenced rather than duplicated.
            ['case.jurisdiction','case','Jurisdiction','text','lead_decision_fact',null,'Which jurisdiction does the client live in: England, Wales, Scotland or Northern Ireland?',['manual'],null,true,10],
            ['calculation.target_di','case','Target DI','money','financial_statement','facts.target_di',null,['ie'],null,false,20],
            ['client.employment_status','case','Employment type','text','lead_field','employment_status',null,['crm','manual'],null,false,30],
            ['income.client_salary','case','Client salary','money','financial_statement','income.client_salary',null,['ie'],null,false,40],
            ['income.universal_credit','case','Universal Credit','money','financial_statement','income.universal_credit',null,['ie'],null,false,50],
            ['household.partner_exists','case','Partner exists','boolean','financial_statement','household.partner_exists',null,['ie'],null,false,60],
            ['income.partner_salary','case','Partner income','money','financial_statement','income.partner_salary',null,['ie'],null,false,70],
            ['household.children_count','case','Resident children','integer','financial_statement','household.children_count',null,['ie'],null,false,80],
            ['household.children_ages','case','Children ages','text','financial_statement','household.children',null,['ie'],null,false,90],
            ['calculation.disposable_income','case','Disposable income','money','financial_statement','calculation.disposable_income',null,['derived'],null,false,100],

            // Property: intentionally limited to the agreed three equity inputs plus homeowner status.
            ['property.is_homeowner','case','Homeowner/property interest','boolean','lead_decision_fact',null,'Does the client own or have a legal/beneficial interest in any property?',['manual'],null,true,110],
            ['property.mortgage_balance','case','Mortgage balance','money','lead_decision_fact',null,'What is the current mortgage balance?',['credit_report','manual'],['fact'=>['key'=>'property.is_homeowner','operator'=>'equals','value'=>true]],true,120],
            ['property.value','case','Property value','money','lead_decision_fact',null,'What is the current property value?',['manual','property_data_future'],['fact'=>['key'=>'property.is_homeowner','operator'=>'equals','value'=>true]],true,130],
            ['property.joint_ownership','case','Joint ownership','boolean','lead_decision_fact',null,'Is the property jointly owned?',['manual'],['fact'=>['key'=>'property.is_homeowner','operator'=>'equals','value'=>true]],true,140],

            // Insolvency history.
            ['case.previous_iva','case','Previous IVA','boolean','lead_decision_fact',null,'Has the client had an IVA before?',['manual','credit_report'],null,true,150],
            ['case.previous_iva_failed','case','Previous IVA failed','boolean','lead_decision_fact',null,'Did the previous IVA fail or terminate rather than complete successfully?',['manual','documents'],['fact'=>['key'=>'case.previous_iva','operator'=>'equals','value'=>true]],true,160],
            ['case.previous_bankruptcy','case','Previous bankruptcy','boolean','lead_decision_fact',null,'Has the client previously been bankrupt?',['manual','credit_report'],null,true,170],

            // Self-employed: deliberately simplified to return-filing readiness.
            ['case.self_employed','case','Self-employed','boolean','lead_decision_fact',null,'Is the client self-employed?',['crm','ie','manual'],null,true,180],
            ['case.self_employed_returns_due','case','Trading long enough for tax returns to be due','boolean','lead_decision_fact',null,'Has the client been trading long enough that they should already have filed tax returns?',['manual'],['fact'=>['key'=>'case.self_employed','operator'=>'equals','value'=>true]],true,190],
            ['case.tax_returns_up_to_date','case','Required tax returns up to date','boolean','lead_decision_fact',null,'Are all tax returns that are currently due up to date?',['manual','documents'],['all'=>[['fact'=>['key'=>'case.self_employed','operator'=>'equals','value'=>true]],['fact'=>['key'=>'case.self_employed_returns_due','operator'=>'equals','value'=>true]]]],true,200],

            // HMRC branch.
            ['case.hmrc_tax_returns_outstanding','case','HMRC tax returns outstanding','boolean','lead_decision_fact',null,'Are any tax returns or self-assessments currently outstanding with HMRC?',['manual','documents'],['has_hmrc_debt'=>true],true,210],
            ['case.hmrc_previous_failed_iva','case','HMRC included in previous failed IVA','boolean','lead_decision_fact',null,'Has HMRC previously been included in an IVA for this client that then failed?',['manual','documents'],['has_hmrc_debt'=>true],true,220],
            ['case.hmrc_prolonged_non_compliance','case','Prolonged HMRC non-compliance','boolean','lead_decision_fact',null,'Has the client had prolonged periods of not filing, not paying, or not following HMRC arrangements?',['manual','documents'],['has_hmrc_debt'=>true],true,230],
            ['case.hmrc_non_compliance_notes','case','HMRC non-compliance notes','text','lead_decision_fact',null,'Briefly describe the HMRC non-compliance history.',['manual'],['fact'=>['key'=>'case.hmrc_prolonged_non_compliance','operator'=>'equals','value'=>true]],false,240],
            ['case.joint_iva','case','Joint IVA proposed','boolean','lead_decision_fact',null,'Is a joint IVA being proposed?',['manual'],['has_hmrc_debt'=>true],true,250],

            // Conduct / gambling.
            ['case.gambling_monthly','case','Monthly gambling','money_or_none','lead_decision_fact',null,'Is there any significant/relevant gambling? If yes, roughly how much per month?',['manual','banking_future'],null,true,260],
            ['case.gamstop_registered','case','GAMSTOP registered','boolean','lead_decision_fact',null,'Is the client registered with GAMSTOP?',['manual'],['fact'=>['key'=>'case.gambling_monthly','operator'=>'gt','value'=>200]],true,270],

            // Partner / specialist conditional facts.
            ['partner.income_evidence_available','case','Partner income evidence available','boolean','lead_decision_fact',null,'Is normal evidence of the partner’s income available?',['manual','documents'],['partner_income_positive'=>true],true,300],
            ['partner.declaration_available','case','Lawson Fox partner declaration available','boolean','lead_decision_fact',null,'If normal partner income evidence is unavailable, can the partner provide the signed Lawson Fox declaration?',['manual'],['all'=>[['partner_income_positive'=>true],['fact'=>['key'=>'partner.income_evidence_available','operator'=>'equals','value'=>false]]]],true,310],
            ['case.immigration_status','case','Immigration/residency status','text','lead_decision_fact',null,'What is the client’s immigration/residency status?',['manual'],null,false,320],
            ['case.uk_driving_licence','case','UK driving licence available','boolean','lead_decision_fact',null,'Does the client have a UK driving licence?',['manual'],['immigration_non_british'=>true],true,330],

            // Evidence retained in the agreed dataset. These inform referral actions but do not block case reasoning.
            ['evidence.bank_statement_months','case','Months of bank statements available','integer','lead_decision_fact',null,'How many months of bank statements are available?',['manual','documents_future'],null,false,400],
            ['evidence.income_proof_available','case','Client income evidence available','boolean','lead_decision_fact',null,'Is current client income evidence available?',['manual','documents_future'],null,false,410],
            ['evidence.benefit_proof_available','case','Benefit evidence available','boolean','lead_decision_fact',null,'Is current benefit evidence available?',['manual','documents_future'],['benefits_positive'=>true],false,420],
            ['evidence.uc_breakdown_available','case','UC breakdown available','boolean','lead_decision_fact',null,'Is the Universal Credit breakdown available?',['manual','documents_future'],['uc_positive'=>true],false,430],
            ['evidence.previous_iva_termination_docs','case','Previous IVA termination documents available','boolean','lead_decision_fact',null,'Are the previous IVA termination documents available?',['manual','documents_future'],['fact'=>['key'=>'case.previous_iva_failed','operator'=>'equals','value'=>true]],false,440],
            ['evidence.self_employed_docs_complete','case','Self-employed/tax evidence available','boolean','lead_decision_fact',null,'Is the required self-employed/tax evidence available?',['manual','documents_future'],['fact'=>['key'=>'case.self_employed','operator'=>'equals','value'=>true]],false,450],

            // Debt/account reasoning facts. Creditor and balance remain first-class debt columns.
            ['debt.creditor','debt','Creditor','text','debt_column','creditor_id',null,['credit_report','manual'],null,false,500],
            ['debt.balance','debt','Balance','money','debt_column','balance',null,['credit_report','manual'],null,false,510],
            ['voting.representative','debt','Voting representative / voting house','text','derived','voting_house',null,['creditor_intelligence'],null,false,520],
            ['debt.product_type','debt','Product/account type','text','debt_decision_fact',null,'What type of debt/account is this?',['credit_report','manual'],null,false,530],
            ['debt.account_reference','debt','Account/reference number','text','debt_decision_fact',null,'What is the account/reference number?',['credit_report','manual'],null,false,540],
            ['debt.is_joint','debt','Joint debt','boolean','debt_decision_fact',null,'Is this debt joint?',['credit_report','manual'],null,false,550],
            ['debt.last_spend_date','debt','Last spend / recent use date','date','debt_decision_fact',null,'When was this account last used for spending?',['credit_report','manual'],null,false,560],
            ['debt.attachment_of_earnings','debt','Attachment of earnings','boolean','debt_decision_fact',null,'Is there an attachment of earnings on this debt?',['manual','documents'],null,false,570],
            ['debt.attachment_of_benefit','debt','Attachment of benefits','boolean','debt_decision_fact',null,'Is there an attachment of benefits on this debt?',['manual','documents'],null,false,580],
            ['debt.keep_vehicle','debt','HP/vehicle agreement being retained','boolean','debt_decision_fact',null,'Is the HP/vehicle agreement being retained?',['manual'],null,false,590],
        ];

        foreach ($rows as $row) {
            [$factKey,$scope,$label,$type,$storageType,$storageKey,$question,$sources,$applicability,$required,$priority] = $row;
            DB::table('decision_fact_definitions')->updateOrInsert(
                ['fact_key'=>$factKey],
                [
                    'scope'=>$scope,
                    'label'=>$label,
                    'data_type'=>$type,
                    'storage_type'=>$storageType,
                    'storage_key'=>$storageKey,
                    'question'=>$question,
                    'source_hints'=>json_encode($sources),
                    'applicability'=>$applicability===null?null:json_encode($applicability),
                    'reasoning_required'=>$required,
                    'question_priority'=>$priority,
                    'is_active'=>true,
                    'is_system'=>true,
                    'metadata'=>json_encode(['schema_version'=>1]),
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]
            );
        }

        // Legacy facts remain readable/writeable for historical assessments but are not part of
        // the new readiness questionnaire unless explicitly reactivated later.
        $legacy = [
            'case.hmrc_majority','case.hmrc_deduction_from_income','case.benefits_only','case.seiss_debt','case.vat_debt',
            'case.self_employed_trading_months','case.self_employed_profitable','case.self_employed_has_employees','case.self_employed_partnership',
            'case.vulnerable_client','evidence.bank_statements_continuous','evidence.bank_statements_all_accounts',
            'evidence.bank_statements_dated_last_3_months','evidence.uc_journal_available','evidence.credit_report_available',
            'evidence.debt_proof_complete','evidence.outgoings_proof_available','partner.bank_statements_available',
            'partner.wage_slips_available','property.valuation_evidence','property.secured_loans_total','property.ownership_percent',
            'property.owned_outright','property.beneficial_interest_notes','property.adaptations','property.remortgage_restricted',
            'property.fixed_rate_end','dmp.contractual_payments_total','dmp.priority_debt_included','dmp.council_tax_or_bailiff_included',
            'debt.contractual_payment','debt.payments_made','debt.current_provider','debt.repossessed_mortgage','debt.is_priority',
            'debt.is_payday','debt.is_council_tax','debt.is_bailiff','debt.current_property','debt.is_criminal','debt.is_pcn',
            'debt.is_car_related','debt.is_guarantor','debt.creditor_location','debt.recent_spend_2_months','debt.recent_spend_3_months',
            'debt.account_opened_date','debt.finance_taken_date','voting.representative_override',
        ];
        foreach ($legacy as $key) {
            DB::table('decision_fact_definitions')->updateOrInsert(
                ['fact_key'=>$key],
                [
                    'scope'=>str_starts_with($key,'debt.')||str_starts_with($key,'voting.')?'debt':'case',
                    'label'=>$key,
                    'data_type'=>'legacy',
                    'storage_type'=>str_starts_with($key,'debt.')||str_starts_with($key,'voting.')?'debt_decision_fact':'lead_decision_fact',
                    'reasoning_required'=>false,
                    'question_priority'=>5000,
                    'is_active'=>false,
                    'is_system'=>true,
                    'metadata'=>json_encode(['legacy'=>true]),
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_dynamic_rules');
        Schema::dropIfExists('decision_fact_definitions');
    }
};
