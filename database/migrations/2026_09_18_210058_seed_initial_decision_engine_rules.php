<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $houses = DB::table('voting_houses')->get()->mapWithKeys(
            fn ($h) => [mb_strtolower(trim((string) $h->key)) => $h->id]
        );
        foreach (['WATCH', 'TIX', 'Evolve'] as $key) {
            $normalised = mb_strtolower($key);
            if (! $houses->has($normalised)) {
                $houses->put($normalised, DB::table('voting_houses')->insertGetId([
                    'key' => $key, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        }

        $add = function (string $sourceName, string $sheet, string $location, string $partner, string $scope, string $category, string $text, ?string $house = null, string $severity = 'requirement', string $sourceType = 'workbook') use ($houses) {
            $sourceId = DB::table('decision_rule_sources')->insertGetId([
                'source_type' => $sourceType, 'name' => $sourceName, 'sheet' => $sheet,
                'location' => $location, 'original_text' => $text,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('decision_rules')->insert([
                'scope_type' => $scope, 'voting_house_id' => $house ? ($houses[mb_strtolower($house)] ?? null) : null,
                'partner_key' => $partner, 'category' => $category, 'requirement_text' => $text,
                'severity' => $severity, 'source_id' => $sourceId, 'is_active' => $severity !== 'historical',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $rules = [
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 6','zebra','partner','packaging','Minimum DI | £110 (must re-pay over £6600 during term)'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 7','zebra','partner','packaging','Minimum Debt Level | £7000'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 8','zebra','partner','evidence','Bank Statements | 3 continuous full month as a minimum, Income and expenditure should correspond with the I & E or be explained in case notes (must show clients name and address)'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 9','zebra','partner','income','Universal Credit | Breakdown of the what is included is required | Universal Credit Advance | MUST be entered as a debt and any deduction amount added back on to the income on the I&E'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 10','zebra','partner','gambling','Gambling | GAMSTOP required for any cases where the amount exceeds the monthly IVA contribution.'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 11','zebra','partner','evidence','Proof of all income | At least 1 wage slip dates within the last 3 months/benefit letter/bank statement/evidence of board/CSA/Full Tax Return (not the calculation) | £1000 minimum income.'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 13','zebra','partner','partner_income_evidence',"Partner's income | Proof of Partner income required"],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 19','zebra','partner','rent_arrears','Rent Arrears (current property) | This may be included in the IVA, as long as the landlord/agent has confirmed the current tenancy will not be effected.'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 20','zebra','partner','property','Assets (Property, Vehicles, Livestock etc) | All assets must be evidenced including the valuation obtained and the clients intention explained, eg retain/exclude/sell'],
            ['Zebra Criteria (1).xlsx','Statutory Rules','Row 21','zebra','partner','hmrc',"HMRC Majority | Any INTERLOCKING IVA with a JOINT HMRC MAJORITY debt must be referred as 2 SOLE IVA'S, HMRC will reject all interlocking IVA's where there is a joint debt"],
        ];

        foreach ($rules as $r) $add(...$r);

        $watch = [
            [6,'Must have 2 debts minimum, owed to 2 different creditors'],
            [7,"No recent spending within 3 months, except for essential goods (adult, work, school or children's clothing)"],
            [8,'No 3rd party income to sustain I&E'],
            [10,'Maximum car value is £8,999 if owned outright (If over, Watch will reject or add modification to sell)'],
            [11,'Maximum HP/PCP is £400 p/m, if over, downgrade modification will be applied. Client should be made aware from the outset'],
            [12,'If equity in vehicle, Watch will apply mod for client to sell vehicle or reject'],
            [15,'Board and lodging - maximum is £450 (anything over this amount would require 3 months historical evidence to verify it)'],
            [16,'Client must not be able to clear their debt level within 72 months, however an assumed 17% interest can be used in the calculation'],
            [19,'Average property valuation will be used, using two or three online appraisal sites (Zoopla, Mouseprice, other)'],
            [20,'Homeowner equity - must not exceed 100k (unless over 55 or have adaptations to property - will need evidence of adaptations)'],
            [21,'Will NOT accept owned outright cases'],
            [22,'Student loan income needs to be offset in full - cannot use a loan to repay unsecured borrowing - sustabibilty note also required for when income is lost'],
            [23,'Watch will abstain from voting if the client is aged over 80'],
        ];
        foreach ($watch as [$row,$text]) $add('Zebra Criteria (1).xlsx','Watch','Row '.$row,'zebra','voting_house','voting_house',$text,'WATCH');

        $tix = [
            [27,'Must have 2 debts minimum, owed to 2 different creditors unless direct negotiations attempted/arrangement failed'],
            [28,'Reject recent spending in last 6 months (Tesco/Shop Direct/Creation/Lendable)'],
            [30,'High equity in property will be accepted'],
            [31,'TIX will accept owned outright properties'],
            [32,'Maximum car value is £10,000 (If over, TIX will reject or add modification to sell)'],
            [33,'Maximum HP/PCP is £300 p/m, if over, downgrade or extension modification will be applied'],
        ];
        foreach ($tix as [$row,$text]) $add('Zebra Criteria (1).xlsx','Watch','Row '.$row,'zebra','voting_house','voting_house',$text,'TIX');

        $add('Zebra Criteria (1).xlsx','Watch','Row 37','zebra','voting_house','voting_house','Now merged with watch','Evolve');

        foreach ([
            [41,"Evidence MUST be on file to confirm that client's Tax Returns are up to date - this should be proven using live chat/3way call"],
            [42,'£150 minimum DI'], [44,'BKY dividend MUST be lower than IVA'], [45,'Will reject if AOE in place'],
            [46,'Reject where the debt stems from historical unpaid self-employed tax and there is a record of poor compliance over multiple years.'],
        ] as [$row,$text]) $add('Zebra Criteria (1).xlsx','Watch','Row '.$row,'zebra','creditor_class','hmrc',$text);

        foreach ([
            ['Avondale - Assure Criteria(1).xlsx','assure','Row 6','packaging','Minimum DI | £110 (must re-pay over £6600 during term)'],
            ['Avondale - Assure Criteria(1).xlsx','assure','Row 7','packaging','Minimum Debt Level | £7000'],
            ['Avondale - Assure Criteria(1).xlsx','assure','Row 11','evidence','Proof of all income | At least 1 wage slip dates within the last 3 months/benefit letter/bank statement/evidence of board/CSA/Full Tax Return (not the calculation) | £1000 minimum income.'],
            ['Avondale - Assure Criteria(1).xlsx','assure','Row 13','partner_income_evidence',"Partner's income | Proof of Partner income required"],
            ['Avondale - Assure Criteria(1).xlsx','assure','Row 19','rent_arrears','Rent Arrears (current property) | This may be included in the IVA, as long as the landlord/agent has confirmed the current tenancy will not be effected.'],
            ['Avondale - Lawson Fox Criteria(1).xlsx','lawson_fox','Row 6','packaging','Minimum DI | 110 Per case, we do not run joint IVAs so partners should be referred as 2 separate cases with each others IVA payment included in the I&E.'],
            ['Avondale - Lawson Fox Criteria(1).xlsx','lawson_fox','Row 7','packaging','Minimum Debt Level | 7500'],
            ['Avondale - Lawson Fox Criteria(1).xlsx','lawson_fox','Row 11','evidence','Proof of all income | At least 1 wage slip dates within the last 3 months/benefit letter/bank statement/evidence of board/CSA/Full Tax Return (not the calculation) | £1000 minimum income.'],
            ['Avondale - Lawson Fox Criteria(1).xlsx','lawson_fox','Row 13','partner_income_evidence',"Partner's income & Children | Partner Name and email required for all cases with a partner. Dates of birth for all children including any they pay child support for."],
            ['Avondale - Lawson Fox Criteria(1).xlsx','lawson_fox','Row 19','rent_arrears','Rent Arrears (current property) | Whe do not propose current rent arrears they should have this built inot the I&E with a a confirmed payment plan for the arrears'],
        ] as [$source,$partner,$location,$category,$text]) $add($source,'Statutory Rules',$location,$partner,'partner',$category,$text);

        $add('User operational instruction','Internal instruction','Conversation instruction','lawson_fox','partner','immigration_evidence','Immigrant cases can be run where the client has a UK driving licence.',null,'requirement','internal_instruction');
        $add('User operational instruction','Internal instruction','Conversation instruction','lawson_fox','partner','partner_income_evidence','Partner income can be evidenced by emailing the partner a declaration/document to sign; partner bank statements or wage slips are not required for this route.',null,'requirement','internal_instruction');

        foreach ([
            [3,'packaging','Minimum debt level £6000'], [5,'packaging','Minimum DI on all cases £100'],
            [7,'ie','Financial Statement is within SFS guidelines'], [9,'benefits','DLA/PIP must always be offset'],
            [12,'evidence','We require 1 full month wage slip for each wage on the I&E (Including partners where applicable). This should be dated in the last 3 months. (unless not available for exceptional circumstance Portal or similar can be used with managers sign off)'],
            [15,'evidence','Universal Credit need full journal dated in last 3 months.'],
            [17,'self_employed','SELF EMPLOYED = TAX RETURN / 3 MONTHS BANKING'],
            [18,'self_employed',"IF NEWLY SELF EMPLOYED NEEDS TO BE A MINIMUM OF 3 MONTH'S IN THE JOB AND CONFIRMATION OF HOURS PAY ECT"],
            [22,'evidence','Bank Statement needs to be dated within the last 3months - 1 full month for every account (including savings)'],
            [24,'gambling','Gambling MUST BE under £1000 along GAMSTOP if over £200.'],
            [38,'hmrc','HMRC majority – MUST NOT have a deduction from income or benefits (will reject).'],
            [39,'hmrc','HMRC majority – WILL REJECT IF PREVIOUS IVA OR BANKRUPTCY'],
            [41,'hmrc','HMRC - WILL REJECT if more equity than their debt.'],
            [42,'hmrc','HMRC - WILL REJECT if return hugher in bankruptcy.'],
            [47,'hmrc','HMRC: WILL REJECT IF BENEFTIS ONLY'],
            [51,'council','Council Majority - MUST NOT have a deduction from income or benefits (will reject) - CASE BY CASE CHECK COUNCIL LIST.'],
            [55,'creditor','Shop Direct - So long as not spent in last 3 months statement date or 4 months of order. ACCOUNT AGE: must be atleast 6 months old.'],
            [57,'creditor','Creation - So long as not spent in last 3 months statement date or 4 months of order. PLEASE CAN WE NOT RUN ANY FURTHER TRIALS ON RECENT SPEND WITH SYGMA / CREATION / LASER REGARDLESS OF THE REASON.'],
            [59,'creditor','Link - Mid SFS guidelines ONLY need to be used and £12,000 min debt level - REJECT IF EQUITYMORE THAN THEIR DEBT - REJECT IF BENEFITS more than 10% of household income -= REJECT IF PREVIOUS IVA FAILED DUE TO ARREARS'],
        ] as [$row,$category,$text]) $add('Avondale - TIG criteria(1).xlsx','TIG Criteria','Row '.$row,'tig','partner',$category,$text);

        foreach ([
            [7,'Will reject IVA if debt can be paid off in less than 6 years'],
            [9,'Will reject IVA if Bankruptcy dividend is higher than IVA'],
            [11,'Will reject IVA if equity is greater than debt'],
            [13,'Will reject if the client only has debt with 1 lender (Need sperate lender account of more than £500)'],
            [15,'Will reject (on all accounts) if any spending within 3 months'],
            [17,'Will reject if the client has children over 13 and no sustainability Paragraph (Drafter)'],
            [19,'Will abstain (not vote) if client aged 80 or above'],
            [21,'Will request for car to be downgraded to £4,500 if more than £9,000 and funds brought into IVA'],
            [23,'Will modify if car HP is >£400 per month (unless family help or we can evidence a valid reason)'],
            [25,'Will require 3 months clean bank statement if gambling is the main cause of debt problem'],
            [27,'If the IVA has been proposed previosuly - the main content (I&E, assets, liabilities) needs to remain the same or a reason for the changes'],
            [29,'Will reject all cases where antecedent transactions appear, irrelevant of the dividend factor'],
            [31,'Will reject if car finance in last 3 months, without evidence of valid reason (eg old car scrapped with proof)'],
        ] as [$row,$text]) $add('Avondale - TIG criteria(1).xlsx','Watch Criteria','Row '.$row,'tig','voting_house','voting_house',$text,'WATCH');

        foreach ([
            [7,'Will reject Shop Direct (Very, Littlewoods) if spend in last 3 months, if account is less than 6 months old will also reject regardless of spend'],
            [9,'Will reject Creation (Sygma, Laser) if spend in last 4 months'],
            [12,'Will modify if car HP is >£250 per month (unless family help or we can evidence a valid reason)'],
        ] as [$row,$text]) $add('Avondale - TIG criteria(1).xlsx','TIX Criteria','Row '.$row,'tig','voting_house','voting_house',$text,'TIX');
    }

    public function down(): void
    {
        $names = ['Zebra Criteria (1).xlsx','Avondale - Assure Criteria(1).xlsx','Avondale - Lawson Fox Criteria(1).xlsx','Avondale - TIG criteria(1).xlsx','User operational instruction'];
        $ids = DB::table('decision_rule_sources')->whereIn('name',$names)->pluck('id');
        DB::table('decision_rules')->whereIn('source_id',$ids)->delete();
        DB::table('decision_rule_sources')->whereIn('id',$ids)->delete();
    }
};
