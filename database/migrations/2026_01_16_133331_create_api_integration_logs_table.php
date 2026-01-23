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
        Schema::create('api_integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_project_access_id')->nullable()->constrained('company_project_access')->onDelete('set null');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Request details
            $table->string('endpoint', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->json('request_payload')->nullable(); // sanitized
            $table->integer('response_status')->nullable();
            $table->json('response_body')->nullable(); // sanitized
            $table->text('error_message')->nullable();
            
            // Rate limiting tracking
            $table->boolean('rate_limit_hit')->default(false);
            
            // Timing
            $table->integer('duration_ms')->nullable();
            
            $table->timestamps();
            
            $table->index(['project_id', 'created_at']);
            $table->index(['company_project_access_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_integration_logs');
    }
};
