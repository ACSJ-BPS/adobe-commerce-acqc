<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->unsignedInteger('line_number')->nullable();
            $table->string('rule_id')->nullable();
            $table->string('rule_name');
            $table->enum('severity', ['blocker', 'critical', 'error', 'warning', 'info', 'minor']);
            $table->string('category')->nullable()->comment('security, performance, maintainability, etc.');
            $table->text('description');
            $table->text('recommendation')->nullable();
            $table->char('fingerprint', 40)->nullable()->comment('SHA1 of file+line+rule for dedup');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['scan_id', 'severity']);
            $table->index(['scan_id', 'category']);
            $table->index(['scan_id', 'resolved_at']);
            $table->index('fingerprint');
        });

        Schema::create('weight_configs', function (Blueprint $table) {
            $table->id();
            $table->enum('tier', ['global', 'profile', 'project'])->default('global');
            $table->string('profile_name')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('metric_key');
            $table->decimal('weight', 5, 2)->default(0);
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();

            $table->index(['tier', 'profile_name']);
            $table->index(['tier', 'project_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['entity_type', 'entity_id']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('weight_configs');
        Schema::dropIfExists('findings');
    }
};
