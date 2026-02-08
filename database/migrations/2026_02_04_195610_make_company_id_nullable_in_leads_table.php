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
        Schema::table('leads', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['company_id']);
            // Make the column nullable
            $table->unsignedBigInteger('company_id')->nullable()->change();
            // Re-add the foreign key constraint (nullable foreign keys are allowed)
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['company_id']);
            // Make the column not nullable again
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            // Re-add the foreign key constraint
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }
};
