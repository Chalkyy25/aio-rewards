<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Idempotency marker for the post-verification Ambassador welcome
            // email. NULL = never sent. Populated in a single atomic UPDATE by
            // SendAmbassadorWelcomeAfterVerified so duplicate Verified events
            // (multiple clicks, race conditions, replayed jobs) cannot
            // re-trigger the mail.
            $t->timestamp('welcome_email_sent_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('welcome_email_sent_at');
        });
    }
};
