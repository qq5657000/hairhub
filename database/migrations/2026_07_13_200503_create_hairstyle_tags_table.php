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
        Schema::create('hairstyle_tags', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 100)->comment('标签名称');
            $table->string('name_en', 150)->default('')->comment('英文名称预留');
            $table->string('slug', 150)->comment('标签标识');
            $table->unsignedTinyInteger('type')->default(0)->comment('标签类型');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamps();

            $table->unique('slug', 'uk_slug');
            $table->index(['type', 'status', 'sort'], 'idx_type_status_sort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hairstyle_tags');
    }
};
