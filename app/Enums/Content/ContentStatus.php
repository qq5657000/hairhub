<?php

namespace App\Enums\Content;

/**
 * 内容统一生命周期状态。
 *
 * 对应 articles.status、videos.status（对应
 * doc/v1.0/database/03-内容模块.md 三、状态设计）：
 * - 0 = draft：草稿，仅后台可见；
 * - 1 = pending_review：待审核，已提交但未通过审核；
 * - 2 = published：已发布，满足前台展示条件时对外可见；
 * - 3 = offline：已下线，曾发布过，现手动下线。
 *
 * 合法状态流转（由 canTransitionTo() 统一维护，业务层不得绕过）：
 * draft -> pending_review
 * pending_review -> draft
 * pending_review -> published
 * published -> offline
 * offline -> published
 */
enum ContentStatus: int
{
    case Draft = 0;
    case PendingReview = 1;
    case Published = 2;
    case Offline = 3;

    /**
     * @var array<int, array<int, int>>
     */
    private const TRANSITIONS = [
        self::Draft->value => [self::PendingReview->value],
        self::PendingReview->value => [self::Draft->value, self::Published->value],
        self::Published->value => [self::Offline->value],
        self::Offline->value => [self::Published->value],
    ];

    public function label(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::PendingReview => '待审核',
            self::Published => '已发布',
            self::Offline => '已下线',
        };
    }

    /**
     * 供 Dcat Admin 展示状态标签使用的颜色标识（default/primary/warning/success/danger）。
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'default',
            self::PendingReview => 'warning',
            self::Published => 'success',
            self::Offline => 'danger',
        };
    }

    /**
     * 判断是否允许从当前状态切换到目标状态，状态流转规则统一在此维护，
     * ArticleService / VideoService 均不得自行编写状态流转判断逻辑。
     *
     * 目标状态与当前状态相同时视为合法的“空流转”（例如草稿另存为草稿、
     * 已发布内容重新提交仍保持已发布），不属于 TRANSITIONS 定义的真实状态
     * 迁移场景，因此不查表直接放行；具体是否产生副作用（审核字段/发布时间）
     * 由调用方（ArticleService::changeStatus() / VideoService::changeStatus()）
     * 决定，本方法只负责判断“是否允许”。
     */
    public function canTransitionTo(self $target): bool
    {
        if ($target === $this) {
            return true;
        }

        return in_array($target->value, self::TRANSITIONS[$this->value] ?? [], true);
    }

    public static function options(): array
    {
        return array_combine(
            self::values(),
            array_map(fn (self $case) => $case->label(), self::cases())
        );
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function isValid(int $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
