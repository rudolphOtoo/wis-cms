<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pastoral_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('member_id')->constrained()->cascadeOnDelete();
            $table->uuid('author_user_id')->constrained('users');
            $table->uuid('branch_id')->constrained();
            $table->string('category')->default('general');
            $table->string('title');
            $table->text('body');
            $table->boolean('follow_up_required')->default(false);
            $table->date('follow_up_date')->nullable();
            $table->boolean('follow_up_completed')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'created_at']);
            $table->index(['member_id', 'created_at']);
            $table->index(['follow_up_required', 'follow_up_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pastoral_notes');
    }
};
