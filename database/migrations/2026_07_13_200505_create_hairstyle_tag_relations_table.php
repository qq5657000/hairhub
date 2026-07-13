<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hairstyle_tag_relations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('hairstyle_id')->comment('发型 ID');
            $table->unsignedBigInteger('tag_id')->comment('标签 ID');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->unique(['hairstyle_id', 'tag_id'], 'uk_hairstyle_tag');
            $table->index(['tag_id', 'hairstyle_id'], 'idx_tag_hairstyle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hairstyle_tag_relations');
    }
};
