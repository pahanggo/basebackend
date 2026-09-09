<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core tables for the packages/workflow engine. They live on the "workflow"
 * SQLite connection (config/database.php), isolated from both the main
 * application database and any downstream project's own schema.
 */
return new class extends Migration
{
    protected $connection = 'workflow';

    public function up(): void
    {
        try {
        Schema::connection('workflow')->create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('model'); // fully-qualified class name of the target Eloquent model
            $table->foreignId('published_version_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('workflow')->create('workflow_definition_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('graph'); // nodes + edges, per the "Graph shape" design
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'version']);
        });

        Schema::connection('workflow')->create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->foreignId('workflow_definition_version_id')->constrained('workflow_definition_versions')->cascadeOnDelete();
            // Polymorphic reference to a model on the MAIN application connection (or a
            // downstream project's own connection) — deliberately not a DB-level foreign key.
            $table->string('workflowable_type');
            $table->unsignedBigInteger('workflowable_id');
            $table->string('status')->default('active'); // active | completed | cancelled
            $table->timestamps();

            $table->index(['workflowable_type', 'workflowable_id']);
        });

        Schema::connection('workflow')->create('workflow_instance_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('node_id'); // node id within the version's graph JSON
            $table->string('status')->default('active'); // active | consumed
            // Ties parallel tokens spawned by the same fork back together, so a join
            // knows which sibling tokens it is waiting on.
            $table->string('fork_group_id')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_instance_id', 'status']);
        });

        Schema::connection('workflow')->create('workflow_instance_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('workflow_instance_token_id')->nullable();
            $table->string('from_node_id')->nullable();
            $table->string('to_node_id');
            $table->string('edge_id')->nullable();
            $table->string('trigger'); // manual | automatic | webhook | timer
            $table->string('actor_type')->nullable(); // e.g. App\Models\Auth\User, or null for system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('inputs')->nullable(); // captured transition-time input values
            $table->timestamp('created_at')->nullable();

            $table->index(['workflow_instance_id', 'created_at']);
        });

        Schema::connection('workflow')->create('workflow_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_token_id')->constrained('workflow_instance_tokens')->cascadeOnDelete();
            $table->string('edge_id');
            $table->string('stage')->nullable(); // label for escalation-chain stages
            $table->timestamp('fire_at');
            $table->timestamp('fired_at')->nullable();
            $table->timestamps();

            $table->index(['fire_at', 'fired_at']);
        });

        Schema::connection('workflow')->create('workflow_instance_pending_actors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('workflow_instance_token_id')->constrained('workflow_instance_tokens')->cascadeOnDelete();
            $table->string('edge_id');
            $table->string('actor_type'); // role | permission | user
            $table->unsignedBigInteger('actor_id');
            $table->timestamps();

            $table->index(['actor_type', 'actor_id']);
        });
        } catch (Throwable $e) {}
    }

    public function down(): void
    {
        Schema::connection('workflow')->dropIfExists('workflow_instance_pending_actors');
        Schema::connection('workflow')->dropIfExists('workflow_timers');
        Schema::connection('workflow')->dropIfExists('workflow_instance_history');
        Schema::connection('workflow')->dropIfExists('workflow_instance_tokens');
        Schema::connection('workflow')->dropIfExists('workflow_instances');
        Schema::connection('workflow')->dropIfExists('workflow_definition_versions');
        Schema::connection('workflow')->dropIfExists('workflow_definitions');
    }
};
