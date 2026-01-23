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
        Schema::create('signup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            
            // Request details
            $table->json('requested_projects'); // [1, 2, 3] project IDs
            $table->json('company_data'); // company info from signup form
            $table->json('contact_person'); // primary user/admin info
            
            // Status
            $table->enum('status', ['pending', 'approved', 'rejected', 'processing', 'partial_approved'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // API Call Logs
            $table->json('api_calls_log')->nullable(); // track API calls made during approval
            
            $table->timestamps();
            
            $table->index('status');
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signup_requests');
    }
};
