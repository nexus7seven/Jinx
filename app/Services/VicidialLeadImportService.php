<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VicidialLeadImportService
{
    public function importByPhone(string $phone): array
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        if ($phone === '') throw new RuntimeException('A phone number is required.');

        $connection = (string) config('services.vicidial.db_connection', 'asterisk');
        $matches = DB::connection($connection)->table('vicidial_list')
            ->where('phone_number', $phone)->orderByDesc('lead_id')->limit(10)->get();
        if ($matches->isEmpty()) throw new RuntimeException('No VICIdial lead was found for phone number '.$phone.'.');

        $linkedIds = Lead::query()->whereIn('vicidial_lead_id', $matches->pluck('lead_id')->all())->pluck('vicidial_lead_id')->map(fn($id)=>(int)$id)->all();
        $available = $matches->filter(fn($row) => !in_array((int)$row->lead_id, $linkedIds, true))->values();
        $row = $available->first() ?? $matches->first();

        $lead = Lead::where('vicidial_lead_id', $row->lead_id)->first();
        $created = false; $linkedExisting = false;
        if (!$lead) {
            $lead = Lead::where('phone_number', $phone)->whereNull('vicidial_lead_id')->latest('id')->first();
            if ($lead) $linkedExisting = true;
        }
        $values = [
            'vicidial_lead_id'=>(int)$row->lead_id, 'phone_number'=>$phone,
            'first_name'=>$row->first_name ?: null, 'last_name'=>$row->last_name ?: null,
            'email'=>$row->email ?: null, 'postcode'=>$row->postal_code ?: null,
            'address_line_1'=>$row->address1 ?: null, 'source'=>$row->source_id ?: null,
            'from_vicidial_webform'=>true,
        ];
        if (!$lead) { $lead = Lead::create($values); $created = true; }
        elseif ($linkedExisting) { $lead->fill(array_filter($values, fn($v)=>$v!==null && $v!==''))->save(); }
        else {
            foreach ($values as $key=>$value) if (blank($lead->{$key}) && filled($value)) $lead->{$key}=$value;
            $lead->from_vicidial_webform=true; $lead->save();
        }
        return ['created'=>$created,'linked_existing'=>$linkedExisting,'lead_id'=>$lead->id,'vicidial_lead_id'=>(int)$row->lead_id,'name'=>$lead->formattedName(),'phone_number'=>$phone,'url'=>url('/lead/'.$lead->id)];
    }
}
