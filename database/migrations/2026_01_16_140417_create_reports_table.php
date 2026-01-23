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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            
            // Report details
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', [
                'sales', 'customer', 'opportunity', 'task', 'activity', 'custom'
            ]);
            
            // Report configuration
            $table->json('filters')->nullable(); // Date range, status, etc.
            $table->json('columns')->nullable(); // Which columns to include
            $table->json('grouping')->nullable(); // Group by fields
            $table->json('sorting')->nullable(); // Sort configuration
            
            // Chart configuration
            $table->enum('chart_type', ['bar', 'line', 'pie', 'table', 'none'])->nullable();
            $table->json('chart_config')->nullable();
            
            // Schedule
            $table->boolean('is_scheduled')->default(false);
            $table->string('schedule_frequency')->nullable(); // daily, weekly, monthly
            $table->json('schedule_config')->nullable();
            $table->json('recipients')->nullable(); // Email recipients
            
            // Access
            $table->boolean('is_shared')->default(false);
            $table->json('shared_with')->nullable(); // User IDs or roles
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_generated_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'type']);
            $table->index('is_active');
        });
        
        // Report execution logs
        Schema::create('report_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->onDelete('cascade');
            $table->foreignId('generated_by')->nullable()->constrained('users')->onDelete('set null');
            
            // Execution details
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('file_path')->nullable(); // Path to generated report file
            $table->string('file_format')->nullable(); // pdf, excel, csv
            $table->integer('record_count')->nullable();
            $table->text('error_message')->nullable();
            
            // Timing
            $table->integer('execution_time_ms')->nullable();
            
            $table->timestamps();
            
            $table->index(['report_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
