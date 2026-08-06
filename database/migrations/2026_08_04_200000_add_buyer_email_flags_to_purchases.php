<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->timestamp('payment_email_sent_at')->nullable()->after('completed_at');
            $t->timestamp('completed_email_sent_at')->nullable()->after('payment_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropColumn(['payment_email_sent_at', 'completed_email_sent_at']);
        });
    }
};
