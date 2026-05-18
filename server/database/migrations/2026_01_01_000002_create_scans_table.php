<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->constrained('users');
            $table->enum('status', ['queued', 'running', 'complete', 'failed'])->default('queued');
            $table->unsignedTinyInteger('score')->nullable()->comment('0-100 health index');
            $table->string('grade', 2)->nullable()->comment('A+, A, B, C, D, F');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);
            $table->json('category_breakdown')->nullable()->comment('Per-category issue counts');
            $table->json('sub_indexes')->nullable()->comment('Security, Maintainability, Performance sub-scores');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
