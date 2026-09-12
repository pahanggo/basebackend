<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('purchase_request_demo')->create('purchase_requests', function (Blueprint $table) {
            $table->id();
            // References the main app's `users` table, which lives on a
            // different connection — deliberately not a real foreign key
            // (see PurchaseRequest::requester()), the same cross-connection
            // approach the engine itself uses for workflowable_type/id.
            $table->unsignedBigInteger('requester_id');
            $table->decimal('amount', 12, 2);
            $table->text('purpose');
            $table->text('hod_remarks')->nullable();
            $table->text('marketing_feedback')->nullable();
            $table->text('technical_feedback')->nullable();
            $table->text('operations_feedback')->nullable();
            $table->text('finance_remarks')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('purchase_request_demo')->dropIfExists('purchase_requests');
    }
};
