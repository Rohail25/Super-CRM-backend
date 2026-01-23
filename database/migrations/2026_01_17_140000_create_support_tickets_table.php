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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            
            // Ticket details
            $table->string('ticket_number')->unique(); // e.g., TKT-2026-001
            $table->string('subject');
            $table->text('description');
            $table->enum('status', ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('type', ['technical', 'billing', 'feature_request', 'bug', 'other'])->default('other');
            
            // SLA tracking
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('first_response_due_at')->nullable();
            $table->dateTime('resolution_due_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            
            // Customer information (if not linked to customer)
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            
            // Source tracking
            $table->string('source')->nullable(); // email, phone, web, api, etc.
            $table->string('channel')->nullable(); // support_email, live_chat, phone, etc.
            
            // Resolution
            $table->text('resolution')->nullable();
            $table->enum('satisfaction_rating', ['very_satisfied', 'satisfied', 'neutral', 'dissatisfied', 'very_dissatisfied'])->nullable();
            $table->text('satisfaction_feedback')->nullable();
            
            // Tags and categorization
            $table->json('tags')->nullable();
            $table->string('category')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'priority']);
            $table->index(['assigned_to', 'status']);
            $table->index('ticket_number');
            $table->index('customer_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
