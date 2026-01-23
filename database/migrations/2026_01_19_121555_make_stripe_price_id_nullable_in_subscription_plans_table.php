<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // Check if index exists before trying to drop it
            $indexes = DB::select("SHOW INDEX FROM subscription_plans WHERE Column_name = 'stripe_price_id'");
            if (!empty($indexes)) {
                // Get the index name (could be unique or regular index)
                $indexName = $indexes[0]->Key_name;
                // Drop the index using raw SQL
                DB::statement("ALTER TABLE subscription_plans DROP INDEX `{$indexName}`");
            }
            // Make the column nullable
            $table->string('stripe_price_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('stripe_price_id')->nullable(false)->change();
            $table->unique('stripe_price_id');
        });
    }
};
