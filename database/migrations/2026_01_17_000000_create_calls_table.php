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
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Who made the call
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->onDelete('set null');
            
            // Call details
            $table->string('contact_name')->nullable(); // Name of person called
            $table->string('contact_phone')->nullable();
            $table->string('source')->nullable(); // Lead source/project
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'no_answer', 'busy', 'cancelled'])->default('scheduled');
            $table->enum('outcome', ['successful', 'no_answer', 'busy', 'voicemail', 'callback_requested', 'not_interested', 'other'])->nullable();
            
            // Scheduling
            $table->dateTime('scheduled_at')->nullable(); // When the call is scheduled
            $table->dateTime('started_at')->nullable(); // When the call actually started
            $table->dateTime('completed_at')->nullable(); // When the call ended
            $table->integer('duration_seconds')->nullable(); // Call duration in seconds
            
            // Call notes
            $table->text('notes')->nullable();
            $table->text('next_action')->nullable(); // What to do next
            $table->dateTime('callback_at')->nullable(); // When to call back
            
            // Results
            $table->boolean('converted_to_opportunity')->default(false);
            $table->decimal('value', 15, 2)->nullable(); // Value if converted
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('scheduled_at');
            $table->index('callback_at');
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
