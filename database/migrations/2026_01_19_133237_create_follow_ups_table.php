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
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade'); // Lead (customer)
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            
            // Follow-up details
            $table->string('title');
            $table->text('notes')->nullable();
            $table->enum('type', ['call', 'email', 'meeting', 'message', 'other'])->default('call');
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'overdue'])->default('scheduled');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            
            // Scheduling
            $table->dateTime('scheduled_at'); // When the follow-up is scheduled
            $table->dateTime('completed_at')->nullable(); // When it was completed
            $table->text('outcome')->nullable(); // Result/notes after completion
            
            $table->timestamps();
            
            $table->index('customer_id');
            $table->index('opportunity_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
