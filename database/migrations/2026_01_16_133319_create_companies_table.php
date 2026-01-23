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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vat')->nullable()->unique();
            $table->text('address')->nullable();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('status');
            $table->index('vat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
