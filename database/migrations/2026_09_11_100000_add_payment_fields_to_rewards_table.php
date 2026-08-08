<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->string('payment_method', 32)->nullable()->after('paid_at');
            $t->string('payment_reference', 190)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
