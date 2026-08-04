<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackagesSeeder extends Seeder
{
    public function run(): void
    {
        Package::updateOrCreate(['slug' => 'iptv-12-months'], [
            'name' => 'IPTV 12 Months',
            'short_description' => 'Full IPTV package, annual subscription.',
            'full_description' => 'All AIO Media channels for a full year.',
            'stripe_price_id' => null,
            'amount_minor' => 6000, 'currency' => 'gbp',
            'duration_label' => '12 months', 'includes_vpn' => false,
            'is_active' => true, 'sort_order' => 10,
        ]);

        Package::updateOrCreate(['slug' => 'iptv-vpn-12-months'], [
            'name' => 'IPTV + VPN 12 Months',
            'short_description' => 'IPTV plus AIO VPN, annual subscription.',
            'full_description' => 'Everything in IPTV 12 Months plus AIO VPN global endpoints.',
            'stripe_price_id' => null,
            'amount_minor' => 8500, 'currency' => 'gbp',
            'duration_label' => '12 months', 'includes_vpn' => true,
            'is_active' => true, 'sort_order' => 20,
        ]);

        Package::updateOrCreate(['slug' => 'vpn-only-12-months'], [
            'name' => 'VPN Only 12 Months',
            'short_description' => 'AIO VPN standalone, annual subscription.',
            'full_description' => 'Full AIO VPN access without IPTV, valid 12 months.',
            'stripe_price_id' => null,
            'amount_minor' => 3500, 'currency' => 'gbp',
            'duration_label' => '12 months', 'includes_vpn' => true,
            'is_active' => true, 'sort_order' => 30,
        ]);
    }
}
