<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 20); // virtual | presencial
            $table->string('platform', 20)->nullable(); // youtube | zoom
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('speaker', 255)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('virtual_url', 2048)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('entry_cost', 12, 2)->default(0);
            $table->text('details')->nullable();
            $table->string('flyer_path', 500)->nullable();
            $table->string('flyer_mime', 120)->nullable();
            $table->string('flyer_original_name', 255)->nullable();
            $table->string('estado', 20)->default('activo');
            $table->timestamps();

            $table->index(['estado', 'starts_at']);
            $table->index('kind');
        });

        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->text('body')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->string('image_mime', 120)->nullable();
            $table->string('image_original_name', 255)->nullable();
            $table->string('estado', 20)->default('activo');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['estado', 'published_at']);
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('estado', 32)->default('pendiente_pago');
            $table->string('payment_method', 32)->nullable();
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->foreignId('payment_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_admin_notes', 500)->nullable();
            $table->string('payment_proof_path', 500)->nullable();
            $table->string('payment_proof_mime', 120)->nullable();
            $table->string('payment_proof_original_name', 255)->nullable();
            $table->timestamps();

            $table->index(['estado', 'event_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('news');
        Schema::dropIfExists('events');
    }
};
