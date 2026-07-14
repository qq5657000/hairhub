<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.face_shape（JSON 数组元素）
 */
enum FaceShape: string
{
    case Round = 'round';
    case Oval = 'oval';
    case Square = 'square';
    case Long = 'long';
    case Heart = 'heart';
    case Diamond = 'diamond';
    case Triangle = 'triangle';
    case InvertedTriangle = 'inverted_triangle';

    public function label(): string
    {
        return match ($this) {
            self::Round => '圆脸',
            self::Oval => '鹅蛋脸',
            self::Square => '方脸',
            self::Long => '长脸',
            self::Heart => '心形脸',
            self::Diamond => '菱形脸',
            self::Triangle => '三角脸',
            self::InvertedTriangle => '倒三角脸',
        };
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
}
