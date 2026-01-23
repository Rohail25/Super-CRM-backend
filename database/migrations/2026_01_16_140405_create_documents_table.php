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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Polymorphic relationship
            $table->nullableMorphs('documentable'); // Can attach to Customer, Opportunity, Task, etc.
            
            // File information
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size'); // in bytes
            $table->string('extension', 10)->nullable();
            
            // Organization
            $table->string('category')->nullable(); // contract, invoice, proposal, etc.
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Additional file metadata
            
            // Access control
            $table->boolean('is_public')->default(false);
            $table->json('access_permissions')->nullable(); // Specific user/role permissions
            
            // Versioning
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_document_id')->nullable()->constrained('documents')->onDelete('cascade');
            
            // Status
            $table->enum('status', ['draft', 'active', 'archived', 'deleted'])->default('active');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'created_at']);
            // Note: nullableMorphs('documentable') already creates index on documentable_type and documentable_id
            $table->index('category');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
