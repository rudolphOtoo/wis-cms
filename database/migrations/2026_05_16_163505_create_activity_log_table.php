<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('activity_log', function (Blueprint $table) {
        $table->id();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->nullableMorphs('subject');
        $table->string('event')->nullable();
        $table->string('causer_type')->nullable();
        $table->uuid('causer_id')->nullable();
        $table->index(['causer_type', 'causer_id']);
        $table->json('attribute_changes')->nullable();
        $table->json('properties')->nullable();
        $table->timestamps();
        $table->index('log_name');
    });
}
};
