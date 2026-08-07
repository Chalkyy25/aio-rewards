<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the milestone ladder with tiers 15 (£170, +£20 bonus) and
 * 20 (£235, +£35 bonus). Idempotent: matches on threshold and only
 * updates the display fields, never overwriting a manually renamed
 * admin tier.
 *
 * Historical Reward snapshots are financially immutable — this migration
 * touches only `reward_milestone_tiers`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $tiers = [
            [
                'threshold' => 15,
                'total_reward_amount_minor' => 17000,
                'bonus_amount_minor' => 2000,
                'currency' => 'gbp',
                'title' => '£170 Reward',
                'description' => 'Reach 15 approved referrals for a £20 cumulative Save & Grow bonus.',
                'display_order' => 30,
                'is_active' => true,
                'is_visible' => true,
                'is_claimable' => true,
            ],
            [
                'threshold' => 20,
                'total_reward_amount_minor' => 23500,
                'bonus_amount_minor' => 3500,
                'currency' => 'gbp',
                'title' => '£235 Reward',
                'description' => 'Maximum reward for a single cycle — £35 cumulative Save & Grow bonus.',
                'display_order' => 40,
                'is_active' => true,
                'is_visible' => true,
                'is_claimable' => true,
            ],
        ];

        foreach ($tiers as $row) {
            $existing = DB::table('reward_milestone_tiers')
                ->where('threshold', $row['threshold'])
                ->first();
            if ($existing) {
                DB::table('reward_milestone_tiers')
                    ->where('id', $existing->id)
                    ->update(array_merge($row, ['updated_at' => $now]));
            } else {
                DB::table('reward_milestone_tiers')->insert(array_merge($row, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        DB::table('reward_milestone_tiers')->whereIn('threshold', [15, 20])->delete();
    }
};
