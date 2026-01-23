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
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            
            // Field definition
            $table->string('name'); // Internal field name (snake_case)
            $table->string('label'); // Display label
            $table->string('model_type'); // Customer, Opportunity, Task, etc.
            $table->enum('field_type', [
                'text', 'textarea', 'number', 'email', 'phone', 'date', 'datetime',
                'boolean', 'select', 'multiselect', 'radio', 'checkbox', 'file', 'url'
            ]);
            
            // Field configuration
            $table->json('options')->nullable(); // For select, multiselect, radio, checkbox
            $table->json('validation_rules')->nullable(); // Validation rules
            $table->text('default_value')->nullable();
            $table->text('help_text')->nullable();
            $table->text('placeholder')->nullable();
            
            // Display settings
            $table->boolean('is_required')->default(false);
            $table->boolean('is_searchable')->default(true);
            $table->boolean('is_filterable')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->string('group')->nullable(); // Group fields together
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'model_type']);
            $table->index('is_active');
        });
        
        // Create pivot table for custom field values
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->onDelete('cascade');
            $table->morphs('fieldable'); // fieldable_type, fieldable_id
            $table->text('value')->nullable(); // Store as text, parse based on field_type
            $table->json('json_value')->nullable(); // For complex values (multiselect, etc.)
            
            $table->timestamps();
            
            $table->unique(['custom_field_id', 'fieldable_type', 'fieldable_id'], 'unique_field_value');
            // Note: morphs('fieldable') already creates index on fieldable_type and fieldable_id
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
