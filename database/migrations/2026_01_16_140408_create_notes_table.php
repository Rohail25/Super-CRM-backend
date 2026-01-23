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
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            // Polymorphic relationship
            $table->nullableMorphs('noteable'); // Can attach to Customer, Opportunity, Task, etc.
            
            // Note content
            $table->text('content');
            $table->string('title')->nullable();
            $table->enum('type', ['note', 'comment', 'call_log', 'meeting', 'email', 'other'])->default('note');
            
            // Privacy
            $table->boolean('is_private')->default(false); // Only visible to creator
            $table->json('shared_with')->nullable(); // Array of user IDs who can see this note
            
            // Pinned/Important
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_important')->default(false);
            
            // Threading for comments
            $table->foreignId('parent_note_id')->nullable()->constrained('notes')->onDelete('cascade');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'created_at']);
            // Note: nullableMorphs('noteable') already creates index on noteable_type and noteable_id
            $table->index('user_id');
            $table->index('is_pinned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
