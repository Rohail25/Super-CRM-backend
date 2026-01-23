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
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            
            // Opportunity details
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('stage', [
                'prospecting', 'qualification', 'proposal', 'negotiation', 
                'closed_won', 'closed_lost', 'on_hold'
            ])->default('prospecting');
            
            // Value and probability
            $table->decimal('value', 15, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedTinyInteger('probability')->default(0); // 0-100
            $table->decimal('weighted_value', 15, 2)->nullable(); // value * probability
            
            // Dates
            $table->date('expected_close_date')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            
            // Source tracking
            $table->string('source')->nullable(); // referral, website, cold_call, etc.
            $table->string('campaign')->nullable();
            
            // Loss reason (if closed_lost)
            $table->text('loss_reason')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'stage']);
            $table->index(['assigned_to', 'stage']);
            $table->index('expected_close_date');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
