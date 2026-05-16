<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('member_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->enum('gender', ['male', 'female']);
            $table->date('date_of_birth')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('occupation')->nullable();
            $table->enum('marital_status', ['single', 'married', 'widowed', 'divorced'])->nullable();
            $table->date('join_date')->nullable();
            $table->boolean('is_baptised')->default(false);
            $table->date('baptism_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'transferred', 'deceased'])->default('active');
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
