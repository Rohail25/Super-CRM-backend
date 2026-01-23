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
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            
            // Webhook configuration
            $table->string('name');
            $table->text('url');
            $table->string('secret')->nullable(); // For HMAC signature
            $table->enum('method', ['POST', 'PUT', 'PATCH'])->default('POST');
            
            // Events to listen to
            $table->json('events'); // ['customer.created', 'opportunity.updated', etc.]
            $table->json('filters')->nullable(); // Additional filters
            
            // Headers
            $table->json('headers')->nullable(); // Custom headers to send
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['active', 'paused', 'failed'])->default('active');
            
            // Retry configuration
            $table->unsignedInteger('max_retries')->default(3);
            $table->unsignedInteger('retry_delay')->default(60); // seconds
            
            // Statistics
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('successful_calls')->default(0);
            $table->unsignedInteger('failed_calls')->default(0);
            $table->timestamp('last_called_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'is_active']);
            $table->index('status');
        });
        
        // Webhook call logs
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('webhooks')->onDelete('cascade');
            
            // Request details
            $table->string('event');
            $table->json('payload');
            $table->json('headers_sent')->nullable();
            
            // Response details
            $table->unsignedInteger('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error_message')->nullable();
            
            // Timing
            $table->integer('duration_ms')->nullable();
            $table->unsignedInteger('attempt_number')->default(1);
            
            // Status
            $table->enum('status', ['pending', 'success', 'failed', 'retrying'])->default('pending');
            
            $table->timestamps();
            
            $table->index(['webhook_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
