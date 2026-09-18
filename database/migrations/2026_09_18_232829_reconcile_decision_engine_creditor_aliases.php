<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->pretending()) return;

        $creditorId = DB::table('creditors')->where('name','London Borough of Harrow')->value('id');
        if ($creditorId) {
            DB::table('creditor_aliases')->updateOrInsert(
                ['creditor_id'=>$creditorId,'normalized_alias'=>'harrow council'],
                ['alias'=>'Harrow Council','updated_at'=>now(),'created_at'=>now()]
            );
        }

        $exit = Artisan::call('jinx:import-decision-creditors', [
            'file'=>'resources/assistant/decision-engine/creditor-intelligence-full.json',
        ]);
        if ($exit !== 0) throw new \RuntimeException('Decision-engine creditor reconciliation import failed: '.Artisan::output());
    }

    public function down(): void
    {
        $creditorId = DB::table('creditors')->where('name','London Borough of Harrow')->value('id');
        if ($creditorId) {
            DB::table('creditor_aliases')->where('creditor_id',$creditorId)->where('normalized_alias','harrow council')->delete();
        }
    }
};
