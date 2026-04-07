<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Creditor;
use App\Models\VotingHouse;
use App\Models\VotingPractice;

class CoreDataSeeder extends Seeder
{
    public function run(): void
    {
        // --- Voting Houses ---
        VotingHouse::insert([
            [
                'key' => 'WATCH',
                'rules_text' => 'WATCH rules: If 25%+ control, specific IVA constraints apply.',
            ],
            [
                'key' => 'TIX',
                'rules_text' => 'TIX rules: If 25%+ control, different IVA constraints apply.',
            ],
        ]);

        // --- Practices ---
        VotingPractice::insert([
            [
                'key' => 'practice1',
                'label' => 'Practice 1',
                'rules_text' => 'Practice 1 rules regarding I&E, gambling, etc.',
            ],
            [
                'key' => 'practice2',
                'label' => 'Practice 2',
                'rules_text' => 'Practice 2 rules regarding I&E, gambling, etc.',
            ],
            [
                'key' => 'practice3',
                'label' => 'Practice 3',
                'rules_text' => 'Practice 3 rules regarding I&E, gambling, etc.',
            ],
        ]);

        // --- Sample Creditors ---
        Creditor::insert([
            [
                'name' => 'Barclaycard',
                'voting_house' => 'WATCH',
                'voting_practice1' => 'accept',
                'voting_practice2' => 'cbc',
                'voting_practice3' => 'accept',
            ],
            [
                'name' => 'Amex',
                'voting_house' => 'TIX',
                'voting_practice1' => 'reject',
                'voting_practice2' => 'reject',
                'voting_practice3' => 'reject',
            ],
            [
                'name' => 'Lowell',
                'voting_house' => 'WATCH',
                'voting_practice1' => 'accept',
                'voting_practice2' => 'accept',
                'voting_practice3' => 'accept',
            ],
        ]);
    }
}