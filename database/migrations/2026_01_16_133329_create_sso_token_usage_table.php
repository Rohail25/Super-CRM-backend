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
        Schema::create('sso_token_usage', function (Blueprint $table) {
            $table->id();
            $table->string('jti', 255)->unique(); // JWT ID claim
            $table->foreignId('company_project_access_id')->constrained('company_project_access')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            
            // Token details
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            // Status
            $table->enum('status', ['issued', 'used', 'expired', 'revoked'])->default('issued');
            
            $table->timestamps();
            
            $table->index('jti');
            $table->index(['expires_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sso_token_usage');
    }
};
