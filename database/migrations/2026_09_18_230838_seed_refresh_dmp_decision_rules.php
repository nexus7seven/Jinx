<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $add = function (int $page, string $category, string $text, string $severity = 'requirement') {
            $sourceId = DB::table('decision_rule_sources')->insertGetId([
                'source_type'=>'refresh_dmp_pdf',
                'name'=>'Refresh DMP Criteria September 2026.pdf',
                'version'=>'September 2026',
                'sheet'=>'Page '.$page,
                'location'=>$category,
                'original_text'=>$text,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
            DB::table('decision_rules')->insert([
                'scope_type'=>'solution',
                'partner_key'=>'refresh_dmp',
                'category'=>$category,
                'requirement_text'=>$text,
                'severity'=>$severity,
                'source_id'=>$sourceId,
                'is_active'=>true,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
        };

        foreach ([
            'Minimum Debt Level: £2,000',
            '2 or more creditors(or multiple lines with 1 creditor)',
            'DMP payment must be 25% of contractual payments',
            'Disposable income of more than £100 per month (£150 when council tax or bailiff included)',
            'Priority Debts DI: £150 or more',
            'No PCNs, or car related debts and no criminal debt',
            'Can’t accept Scottish cases',
        ] as $text) $add(1,'eligibility',$text);

        foreach ([
            'British Gas','Tesco Mobile','Vodafone','Talk Talk','Raylo',
            'Eon – we can only deal with if previous provider',
            'Scottish Power – we can only deal with if previous provider',
            'Commsave','Credit Unions based in Northern Ireland','London Borough of Redbridge',
            'Reventus','Amber Valley – will go for AOE','Basildon Council – will go for AOE',
            'Borough of Barrow in Furness','East Lyndsay','Kings Lynn and West Norfolk',
            'Sandwell','South Tyneside','Swale Council','Whyte & Co','London Borough Newham',
            'Gravesham Council','Ealing Council',
        ] as $text) $add(str_contains($text,'British Gas')||str_contains($text,'Tesco Mobile')||str_contains($text,'Vodafone')||str_contains($text,'Talk Talk')||str_contains($text,'Raylo')||str_contains($text,'Eon')||str_contains($text,'Scottish Power')||str_contains($text,'Commsave')||str_contains($text,'Credit Unions')||str_contains($text,'Redbridge') ? 1 : 2,'rejecting_creditor',$text,'blocked');

        foreach ([
            'EDL, Bamboo, Savvy, Tick Tock Fintern (Abound), Cashfloat & Loans by MAL: 3 contractual payments required before inclusion',
            'Payday Loans: Ideally one payment made before inclusion',
            'EDL (payment rate): 12.5% of contractual payment or a minimum of £15',
            'One Stop Money Shop: Need 50% of contractual payment',
        ] as $text) $add(2,'creditor_stipulation',$text,'conditional');

        foreach ([
            'Mortgage (unless the house is already repossessed)',
            'Hire Purchase – if client wants to keep the car, we will include this repayment in the client’s essential expenditures',
            'Student Loan',
            'Rent arrears for current property',
            'CSA payments',
            'Debts with an Attachment of Earnings – a DMP cannot reverse the AoE',
            'Debts with an Attachment of Benefit – a DMP cannot reverse this',
            'Guarantor Loan',
            'HMCTS debts – we generally cannot deal with',
            'DVLA',
            'TV Licence Debts',
        ] as $text) $add(str_contains($text,'TV Licence') ? 3 : 2,'excluded_debt_type',$text,'blocked');
    }

    public function down(): void
    {
        $ids=DB::table('decision_rule_sources')->where('source_type','refresh_dmp_pdf')->pluck('id');
        DB::table('decision_rules')->whereIn('source_id',$ids)->delete();
        DB::table('decision_rule_sources')->whereIn('id',$ids)->delete();
    }
};
