<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable();
            $table->boolean('is_admin')->default(false);
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('max_advance_days');
            $table->timestamps();
        });
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('identifier');
            $table->text('description')->nullable();
            $table->decimal('hourly_price', 10, 2);
            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['category_id', 'identifier']);
        });
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('space_id')->constrained()->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->decimal('total_price', 10, 2);
            $table->string('status')->default('confirmed');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'starts_at', 'ends_at', 'status']);
            $table->index(['user_id', 'starts_at']);
        });
        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['space_id', 'starts_at', 'ends_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE categories ADD CHECK (max_advance_days BETWEEN 0 AND 365)');
            DB::statement('ALTER TABLE spaces ADD CHECK (hourly_price >= 0 AND capacity > 0)');
            DB::statement("ALTER TABLE reservations ADD CHECK (ends_at > starts_at AND total_price >= 0 AND status IN ('confirmed', 'cancelled')), ADD CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL))");
            DB::statement('ALTER TABLE schedule_blocks ADD CHECK (ends_at > starts_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_blocks');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('spaces');
        Schema::dropIfExists('categories');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['phone', 'is_admin']));
    }
};
