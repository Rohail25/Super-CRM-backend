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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            
            // Polymorphic relationship
            $table->nullableMorphs('taskable'); // Can attach to Customer, Opportunity, Project, etc.
            
            // Task details
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            
            // Dates
            $table->dateTime('due_date')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            
            // Progress tracking
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            
            // Estimated vs actual time
            $table->integer('estimated_hours')->nullable();
            $table->integer('actual_hours')->nullable();
            
            // Recurrence
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_pattern')->nullable(); // daily, weekly, monthly, etc.
            $table->json('recurrence_config')->nullable();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->onDelete('cascade');
            
            // Reminders
            $table->json('reminders')->nullable(); // Array of reminder times
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index('due_date');
            // Note: nullableMorphs('taskable') already creates index on taskable_type and taskable_id
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
