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
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            
            // Tag details
            $table->string('name');
            $table->string('color', 7)->default('#3B82F6'); // Hex color
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            
            // Category/Group
            $table->string('category')->nullable(); // customer, opportunity, task, etc.
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['company_id', 'name', 'category']);
            $table->index('category');
        });
        
        // Polymorphic pivot table for tags
        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->onDelete('cascade');
            $table->morphs('taggable'); // taggable_type, taggable_id
            
            $table->timestamps();
            
            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'unique_tag');
            // Note: morphs('taggable') already creates index on taggable_type and taggable_id
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
