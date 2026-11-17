<?php

use App\Enums\GenerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_generation_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();
            $table->enum('status', array_column(GenerationStatus::cases(), 'value'))
                ->default(GenerationStatus::Queued->value);

            $table->unsignedBigInteger('estimated_provider_cost_nanodollars');
            $table->unsignedBigInteger('estimated_user_charge_nanodollars');
            // Null means unsettled. Zero is a recorded final amount.
            $table->unsignedBigInteger('actual_provider_cost_nanodollars')->nullable();
            $table->unsignedBigInteger('actual_user_charge_nanodollars')->nullable();
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('provider_error_code')->nullable();
            $table->text('provider_error_message')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
