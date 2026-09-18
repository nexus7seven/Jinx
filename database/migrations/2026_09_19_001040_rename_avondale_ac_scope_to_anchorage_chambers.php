<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('decision_rules')
            ->where('partner_key','avondale_ac')
            ->update(['partner_key'=>'anchorage_chambers','updated_at'=>now()]);

        DB::table('creditor_voting_routes')
            ->where('partner_key','avondale_ac')
            ->update(['partner_key'=>'anchorage_chambers','updated_at'=>now()]);

        DB::table('decision_creditor_source_rows')
            ->where('partner_key','avondale_ac')
            ->update(['partner_key'=>'anchorage_chambers','updated_at'=>now()]);

        DB::table('lead_voting_snapshots')
            ->where('partner_key','avondale_ac')
            ->update(['partner_key'=>'anchorage_chambers','updated_at'=>now()]);
    }

    public function down(): void
    {
        DB::table('decision_rules')
            ->where('partner_key','anchorage_chambers')
            ->update(['partner_key'=>'avondale_ac','updated_at'=>now()]);

        DB::table('creditor_voting_routes')
            ->where('partner_key','anchorage_chambers')
            ->update(['partner_key'=>'avondale_ac','updated_at'=>now()]);

        DB::table('decision_creditor_source_rows')
            ->where('partner_key','anchorage_chambers')
            ->update(['partner_key'=>'avondale_ac','updated_at'=>now()]);

        DB::table('lead_voting_snapshots')
            ->where('partner_key','anchorage_chambers')
            ->update(['partner_key'=>'avondale_ac','updated_at'=>now()]);
    }
};
