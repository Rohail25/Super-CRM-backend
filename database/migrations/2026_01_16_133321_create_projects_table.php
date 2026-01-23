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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('integration_type', ['api', 'iframe', 'hybrid'])->default('api');
            
            // API Configuration
            $table->string('api_base_url', 500)->nullable();
            $table->enum('api_auth_type', ['bearer', 'basic', 'oauth2', 'custom'])->default('bearer');
            $table->text('api_key')->nullable(); // encrypted
            $table->text('api_secret')->nullable(); // encrypted
            $table->string('api_signup_endpoint', 255)->nullable();
            $table->string('api_login_endpoint', 255)->nullable();
            $table->string('api_sso_endpoint', 255)->nullable();
            
            // Iframe Configuration
            $table->string('admin_panel_url', 500)->nullable();
            $table->string('iframe_width', 50)->default('100%');
            $table->string('iframe_height', 50)->default('100vh');
            $table->string('iframe_sandbox', 255)->nullable();
            
            // SSO Configuration
            $table->boolean('sso_enabled')->default(true);
            $table->enum('sso_method', ['jwt', 'oauth2', 'redirect', 'legacy_unsafe'])->default('jwt');
            $table->integer('sso_token_expiry')->default(3600); // seconds
            $table->string('sso_redirect_url', 500)->nullable();
            $table->string('sso_callback_url', 500)->nullable();
            
            // Security flags
            $table->boolean('requires_password_storage')->default(false);
            $table->boolean('is_legacy')->default(false);
            
            // Driver class for integration
            $table->string('driver_class')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('slug');
            $table->index('integration_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
