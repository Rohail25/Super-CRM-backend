<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_project_users', function (Blueprint $table) {
            $table->text('external_token')->nullable()->after('external_role');
            $table->timestamp('token_expires_at')->nullable()->after('external_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_project_users', function (Blueprint $table) {
            $table->dropColumn(['external_token', 'token_expires_at']);
        });
    }
};
