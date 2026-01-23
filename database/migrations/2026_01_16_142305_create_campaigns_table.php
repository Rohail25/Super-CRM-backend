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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            
            // Campaign details
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', [
                'email', 'sms', 'social_media', 'advertising', 'content', 'event', 'other'
            ])->default('email');
            $table->enum('status', [
                'draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled'
            ])->default('draft');
            
            // Dates
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            
            // Budget
            $table->decimal('budget', 15, 2)->nullable();
            $table->decimal('spent', 15, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            
            // Target audience
            $table->json('target_audience')->nullable(); // Customer segments, tags, etc.
            $table->json('target_criteria')->nullable(); // Custom targeting criteria
            
            // Content
            $table->string('subject')->nullable(); // For email campaigns
            $table->text('content')->nullable(); // Email body, ad copy, etc.
            $table->json('content_data')->nullable(); // Additional content (images, links, etc.)
            
            // Settings
            $table->json('settings')->nullable(); // Campaign-specific settings
            $table->boolean('is_active')->default(true);
            $table->boolean('track_clicks')->default(true);
            $table->boolean('track_opens')->default(true);
            
            // Statistics
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('converted_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            
            // Performance metrics
            $table->decimal('open_rate', 5, 2)->default(0); // Percentage
            $table->decimal('click_rate', 5, 2)->default(0); // Percentage
            $table->decimal('conversion_rate', 5, 2)->default(0); // Percentage
            $table->decimal('roi', 5, 2)->nullable(); // Return on investment
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'type']);
            $table->index('start_date');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
