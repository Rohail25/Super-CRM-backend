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
            // Add index on created_at for sorting (most important for fixing the error)
            $table->index('created_at', 'leads_created_at_index');
            
            // Add composite index for company_id and created_at (common query pattern)
            $table->index(['company_id', 'created_at'], 'leads_company_created_index');
            
            // Add indexes on commonly filtered columns
            $table->index('status', 'leads_status_index');
            $table->index('source', 'leads_source_index');
            $table->index('category', 'leads_category_index');
            
            // Add index on email for search queries
            $table->index('email', 'leads_email_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_created_at_index');
            $table->dropIndex('leads_company_created_index');
            $table->dropIndex('leads_status_index');
            $table->dropIndex('leads_source_index');
            $table->dropIndex('leads_category_index');
            $table->dropIndex('leads_email_index');
        });
    }
};
