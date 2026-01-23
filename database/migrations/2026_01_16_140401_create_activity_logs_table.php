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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Polymorphic relationship for any model
            $table->nullableMorphs('subject'); // subject_type, subject_id
            
            // Activity details
            $table->string('action'); // created, updated, deleted, viewed, etc.
            $table->string('description')->nullable();
            $table->string('model_type')->nullable(); // Customer, Company, Project, etc.
            $table->unsignedBigInteger('model_id')->nullable();
            
            // Change tracking
            $table->json('old_values')->nullable(); // Before changes
            $table->json('new_values')->nullable(); // After changes
            $table->json('changed_fields')->nullable(); // List of changed fields
            
            // Request metadata
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->string('method', 10)->nullable(); // GET, POST, PUT, DELETE
            
            // Additional context
            $table->json('properties')->nullable(); // Additional metadata
            $table->string('severity', 20)->default('info'); // info, warning, error, critical
            
            $table->timestamps();
            
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['model_type', 'model_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
