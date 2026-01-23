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
        Schema::create('company_project_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_project_access_id')->constrained('company_project_access')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            // External system mapping
            $table->string('external_user_id', 255)->nullable();
            $table->string('external_username', 255)->nullable();
            $table->string('external_role', 100)->nullable();
            
            // Status
            $table->enum('status', ['active', 'suspended', 'revoked'])->default('active');
            $table->timestamp('last_sso_at')->nullable();
            
            // Audit
            $table->timestamps();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->unique(['company_project_access_id', 'user_id'], 'cpu_access_user_unique');
            $table->unique(['company_project_access_id', 'external_user_id'], 'cpu_access_ext_user_unique');
            $table->index('user_id');
            $table->index('external_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_project_users');
    }
};
