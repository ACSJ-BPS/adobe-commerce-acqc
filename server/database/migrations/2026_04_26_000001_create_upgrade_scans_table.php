<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_version', 20);
            $table->string('target_version', 20);
            $table->decimal('uri_score', 5, 2)->nullable();
            $table->char('uri_grade', 1)->nullable();
            $table->unsignedInteger('engineering_hours')->nullable();
            $table->unsignedInteger('qa_hours')->nullable();
            $table->unsignedInteger('infra_hours')->nullable();
            $table->unsignedInteger('total_hours_with_contingency')->nullable();
            $table->string('recommended_timeline', 50)->nullable();
            $table->unsignedInteger('blocker_count')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->longText('full_report_json')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'source_version', 'target_version'], 'idx_project_versions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_scans');
    }
};
