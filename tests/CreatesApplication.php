<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->assertRunningAgainstSafeTestDatabase();

        return $app;
    }

    /**
     * 测试数据库硬保护：禁止测试套件连接到非测试数据库（例如开发库 hairhub_data）。
     *
     * 执行时机说明（保证发生在 RefreshDatabase 迁移之前）：
     * Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle::
     * setUpTheTestEnvironment() 中，先调用 refreshApplication()（即本类的
     * createApplication()，本方法在其内部、Kernel::bootstrap() 完成 .env/.env.testing
     * 加载之后立即执行），随后才调用 setUpTraits() 触发 RefreshDatabase::refreshDatabase()
     * （内部执行 migrate:fresh 清空并重建表结构）。因此本检查必定先于任何迁移/清库操作执行，
     * 一旦条件不满足会直接抛出异常中断测试启动，RefreshDatabase 不会有机会运行。
     *
     * @throws RuntimeException APP_ENV 不是 testing，或数据库名称不符合 _test 约定
     */
    private function assertRunningAgainstSafeTestDatabase(): void
    {
        $environment = app()->environment();

        if ($environment !== 'testing') {
            throw new RuntimeException(
                "测试环境保护：当前 APP_ENV=[{$environment}]，必须为 testing 才允许运行测试套件，".
                '已阻止本次启动以避免误操作非测试环境（如开发/生产环境）。'
            );
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== 'hairhub_test' && ! Str::endsWith($database, '_test')) {
            throw new RuntimeException(
                "测试数据库保护：当前数据库=[{$database}]（连接=[{$connection}]），".
                '必须命名为 hairhub_test 或以 "_test" 结尾才允许运行测试，'.
                '已阻止本次启动以避免误清空/误写入开发数据库（如 hairhub_data）。'
            );
        }
    }
}
