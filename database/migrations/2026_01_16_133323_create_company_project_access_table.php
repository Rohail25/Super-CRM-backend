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
        Schema::create('company_project_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            
            // Integration-specific credentials (encrypted)
            $table->json('api_credentials')->nullable(); // encrypted
            $table->string('external_company_id', 255)->nullable();
            $table->json('external_account_data')->nullable();
            
            // Status
            $table->enum('status', ['pending', 'active', 'suspended', 'revoked', 'partial_failed'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            
            // Metadata
            $table->json('signup_request_data')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('retry_count')->default(0);
            
            // Rate limiting
            $table->integer('rate_limit_per_minute')->default(60);
            $table->integer('rate_limit_per_hour')->default(1000);
            $table->enum('circuit_breaker_state', ['closed', 'open', 'half_open'])->default('closed');
            $table->integer('circuit_breaker_failures')->default(0);
            $table->timestamp('circuit_breaker_reset_at')->nullable();
            
            $table->timestamps();
            
            $table->unique(['company_id', 'project_id']);
            $table->index('status');
            $table->index(['circuit_breaker_state', 'circuit_breaker_reset_at'], 'cpa_cb_state_reset_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_project_access');
    }
};
