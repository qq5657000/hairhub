<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Common\CommonStatus;
use App\Enums\HairColor\HairColorBleachRequirement;
use App\Enums\HairColor\HairColorBrightness;
use App\Enums\HairColor\HairColorMaintenanceLevel;
use App\Enums\HairColor\HairColorSaturation;
use App\Enums\HairColor\HairColorSuitableSkin;
use App\Enums\HairColor\HairColorTemperature;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 发色初始化 Seeder：初始化 14 条示例发色基础资料。
 *
 * 每条发色都关联到具体的二级分类（如“黑色系”“棕色系”），不关联到“自然色系”
 * “浅色系”“个性色系”这类一级分类（一级分类只用于后台分组展示，对应
 * HairColorCategorySeeder 已建立的两级结构）。
 *
 * 不创建任何媒体文件：后台维护发色时只需要上传/选择封面即可，本 Seeder 只负责
 * 把“文字资料”准备好，与 HairstyleSeeder 的定位一致。
 *
 * 幂等性保证（只补充缺失数据，不覆盖后台运营数据）：
 * - 统一使用 `firstOrCreate(['slug' => ...], [...])`：发色始终通过 slug（unique 索引）
 *   定位，若已存在（含已软删除的记录）则直接返回现有记录，不会覆盖 category_id/
 *   status/sort/is_recommended/brightness/temperature/saturation/bleach_required/
 *   maintenance_level/description/ai_prompt/ai_negative_prompt/seo_title/
 *   seo_description/cover_media_id/published_at 等任何字段；
 * - 使用 `withTrashed()` 定位：发色支持软删除，若后台曾软删除过同 slug 的记录，
 *   必须能查到该记录并直接返回，不会因为看不到而重新 create() 触发 slug 唯一索引冲突，
 *   也不会“复活”被软删除的发色；
 * - category_id 通过分类 slug 从 HairColorCategorySeeder 已写入的二级分类数据中查找
 *   实际 ID，运行本 Seeder 前必须先运行 HairColorCategorySeeder；
 * - 不使用 truncate/delete，重复执行不会产生重复发色。
 *
 * color_hex / suitable_skin 的标准化统一交给 HairColor Model 的 Mutator /
 * Cast 完成，本 Seeder 不重复实现相关正则或白名单校验。
 */
class HairColorSeeder extends Seeder
{
    private const NEGATIVE = 'unnatural color banding, patchy or uneven color, visible dark roots mismatch, damaged or frizzy texture, blurry, low quality, changing the person\'s face shape or identity, distorted facial features';

    public function run(): void
    {
        DB::transaction(function (): void {
            $categoryIds = HairColorCategory::query()->pluck('id', 'slug')->all();

            $definitions = $this->hairColorDefinitions();
            $total = count($definitions);

            foreach ($definitions as $index => $definition) {
                $definition['sort'] = ($total - $index) * 10;

                $this->firstOrCreateHairColor($definition, $categoryIds);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $categoryIds  分类 slug => id 映射
     */
    private function firstOrCreateHairColor(array $definition, array $categoryIds): HairColor
    {
        if (! isset($categoryIds[$definition['category_slug']])) {
            throw new \RuntimeException(
                '发色分类不存在，请确认 HairColorCategorySeeder 已先执行：'.$definition['category_slug']
            );
        }

        /** @var HairColor $hairColor */
        $hairColor = HairColor::withTrashed()->firstOrCreate(
            ['slug' => $definition['slug']],
            [
                'category_id' => $categoryIds[$definition['category_slug']],
                'name' => $definition['name'],
                'name_en' => $definition['name_en'],
                'color_hex' => $definition['color_hex'],
                'suitable_skin' => $definition['suitable_skin'],
                'brightness' => $definition['brightness'],
                'temperature' => $definition['temperature'],
                'saturation' => $definition['saturation'],
                'bleach_required' => $definition['bleach_required'],
                'maintenance_level' => $definition['maintenance_level'],
                'description' => $definition['description'],
                'ai_prompt' => $definition['ai_prompt'],
                'ai_negative_prompt' => $definition['ai_negative_prompt'],
                'seo_title' => $definition['seo_title'],
                'seo_description' => $definition['seo_description'],
                'cover_media_id' => 0,
                'status' => CommonStatus::Enabled->value,
                'is_recommended' => $definition['is_recommended'] ? 1 : 0,
                'sort' => $definition['sort'],
                'published_at' => now(),
            ]
        );

        return $hairColor;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hairColorDefinitions(): array
    {
        $negative = self::NEGATIVE;

        return [
            [
                'slug' => 'natural-black',
                'name' => '自然黑',
                'name_en' => 'Natural Black',
                'category_slug' => 'black-tones',
                'color_hex' => '#1C1C1C',
                'suitable_skin' => [HairColorSuitableSkin::All->value],
                'brightness' => HairColorBrightness::Dark->value,
                'temperature' => HairColorTemperature::Neutral->value,
                'saturation' => HairColorSaturation::Low->value,
                'bleach_required' => HairColorBleachRequirement::No->value,
                'maintenance_level' => HairColorMaintenanceLevel::Easy->value,
                'description' => '最接近亚洲人原生发色的自然黑，几乎不挑肤色，褪色后也不会有明显色差，是最省心的基础发色选择。',
                'ai_prompt' => 'natural jet black hair color, uniform deep black tone, glossy healthy finish, no visible highlights or undertones',
                'ai_negative_prompt' => $negative,
                'seo_title' => '自然黑发色效果图 | 全肤色适用零踩雷 - HairHub',
                'seo_description' => '自然黑是最不挑肤色的基础发色，几乎适合所有肤色和场合。上传照片，用 HairHub AI换发色工具在线预览自然黑效果。',
                'is_recommended' => true,
            ],
            [
                'slug' => 'deep-brown',
                'name' => '深棕色',
                'name_en' => 'Deep Brown',
                'category_slug' => 'brown-tones',
                'color_hex' => '#3B2415',
                'suitable_skin' => [HairColorSuitableSkin::Medium->value, HairColorSuitableSkin::Tan->value, HairColorSuitableSkin::WarmSkin->value],
                'brightness' => HairColorBrightness::Dark->value,
                'temperature' => HairColorTemperature::Warm->value,
                'saturation' => HairColorSaturation::Medium->value,
                'bleach_required' => HairColorBleachRequirement::No->value,
                'maintenance_level' => HairColorMaintenanceLevel::Easy->value,
                'description' => '比纯黑更柔和的深棕色，暖调衬肤色，日常灯光下几乎看不出夸张感，是职场通勤最稳妥的染发选择之一。',
                'ai_prompt' => 'deep warm brown hair color, subtle chocolate undertone, natural glossy finish, low-key everyday hair color',
                'ai_negative_prompt' => $negative,
                'seo_title' => '深棕色发色效果图 | 暖调衬肤显气质 - HairHub',
                'seo_description' => '深棕色比纯黑更柔和衬肤，适合黄皮、小麦色肌肤。想知道自己适不适合深棕色？用 HairHub AI换发色工具先看效果。',
                'is_recommended' => true,
            ],
            [
                'slug' => 'chocolate-brown',
                'name' => '巧克力棕',
                'name_en' => 'Chocolate Brown',
                'category_slug' => 'brown-tones',
                'color_hex' => '#4A2C1B',
                'suitable_skin' => [HairColorSuitableSkin::Medium->value, HairColorSuitableSkin::WarmSkin->value],
                'brightness' => HairColorBrightness::Medium->value,
                'temperature' => HairColorTemperature::Warm->value,
                'saturation' => HairColorSaturation::Medium->value,
                'bleach_required' => HairColorBleachRequirement::No->value,
                'maintenance_level' => HairColorMaintenanceLevel::Normal->value,
                'description' => '巧克力棕比深棕色略亮，带一点丝滑光泽感，暖调衬肤又不失时髦感，是四季都很百搭的经典棕色。',
                'ai_prompt' => 'chocolate brown hair color, warm rich brown tone with subtle sheen, smooth glossy finish, medium brightness',
                'ai_negative_prompt' => $negative,
                'seo_title' => '巧克力棕发色效果图 | 丝滑光泽显时髦 - HairHub',
                'seo_description' => '巧克力棕丝滑有光泽，暖调衬肤，适合黄皮肌肤日常染发。上传照片，用 HairHub AI换发色工具预览巧克力棕效果。',
                'is_recommended' => false,
            ],
            [
                'slug' => 'chestnut-brown',
                'name' => '栗子棕',
                'name_en' => 'Chestnut Brown',
                'category_slug' => 'brown-tones',
                'color_hex' => '#5C3A21',
                'suitable_skin' => [HairColorSuitableSkin::Medium->value, HairColorSuitableSkin::Tan->value],
                'brightness' => HairColorBrightness::Medium->value,
                'temperature' => HairColorTemperature::Warm->value,
                'saturation' => HairColorSaturation::Medium->value,
                'bleach_required' => HairColorBleachRequirement::Depends->value,
                'maintenance_level' => HairColorMaintenanceLevel::Normal->value,
                'description' => '栗子棕比巧克力棕更透亮一些，带一点红棕调，显气色效果好，是秋冬季节人气很高的暖调棕色。',
                'ai_prompt' => 'chestnut brown hair color, warm reddish-brown tone, luminous natural finish, medium brightness and saturation',
                'ai_negative_prompt' => $negative,
                'seo_title' => '栗子棕发色效果图 | 秋冬显气色暖调棕 - HairHub',
                'seo_description' => '栗子棕暖调显气色，适合黄皮、小麦色肌肤，秋冬季节尤其合适。用 HairHub AI换发色工具先预览栗子棕上头效果。',
                'is_recommended' => true,
            ],
            [
                'slug' => 'cool-tea-brown',
                'name' => '冷茶棕',
                'name_en' => 'Cool Tea Brown',
                'category_slug' => 'brown-tones',
                'color_hex' => '#5B4636',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value, HairColorSuitableSkin::Light->value, HairColorSuitableSkin::CoolSkin->value],
                'brightness' => HairColorBrightness::Medium->value,
                'temperature' => HairColorTemperature::Cool->value,
                'saturation' => HairColorSaturation::Low->value,
                'bleach_required' => HairColorBleachRequirement::Depends->value,
                'maintenance_level' => HairColorMaintenanceLevel::Normal->value,
                'description' => '冷茶棕带灰调、低饱和度，褪色后不容易发黄发红，视觉上更显白，是冷白皮很适合的棕色系发色。',
                'ai_prompt' => 'cool-toned ash tea brown hair color, muted low-saturation brown with grey undertone, soft matte finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '冷茶棕发色效果图 | 冷白皮显白不泛黄 - HairHub',
                'seo_description' => '冷茶棕灰调显白，适合白皮、冷白皮肌肤，褪色后也不容易发黄。用 HairHub AI换发色工具先看看冷茶棕效果。',
                'is_recommended' => false,
            ],
            [
                'slug' => 'linen-brown',
                'name' => '亚麻棕',
                'name_en' => 'Linen Brown',
                'category_slug' => 'linen-tones',
                'color_hex' => '#8B6F47',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value, HairColorSuitableSkin::Light->value, HairColorSuitableSkin::CoolSkin->value],
                'brightness' => HairColorBrightness::Light->value,
                'temperature' => HairColorTemperature::Cool->value,
                'saturation' => HairColorSaturation::Low->value,
                'bleach_required' => HairColorBleachRequirement::Yes->value,
                'maintenance_level' => HairColorMaintenanceLevel::Normal->value,
                'description' => '亚麻棕带灰调低饱和的哑光质感，是这几年最受欢迎的显白发色之一，通常需要先漂发才能呈现理想的亚麻效果。',
                'ai_prompt' => 'linen brown hair color, ash-toned low-saturation light brown, matte muted finish, soft cool undertone',
                'ai_negative_prompt' => $negative,
                'seo_title' => '亚麻棕发色效果图 | 显白哑光高级感 - HairHub',
                'seo_description' => '亚麻棕哑光显白，是近年最火的染发色之一，适合白皮、冷白皮。染前先用 HairHub AI换发色工具预览亚麻棕效果。',
                'is_recommended' => true,
            ],
            [
                // 分类层级修正说明：原分类结构中“浅色系”被错误当作二级（叶子）分类使用，
                // 修正为一级分类后，本条示例发色改挂到其下的二级分类“金色系”
                // （暖调金棕色，语义上比“浅色系”这个一级分类更贴切）。
                'slug' => 'honey-tea',
                'name' => '蜂蜜茶色',
                'name_en' => 'Honey Tea',
                'category_slug' => 'gold-tones',
                'color_hex' => '#C8934E',
                'suitable_skin' => [HairColorSuitableSkin::Medium->value, HairColorSuitableSkin::Tan->value, HairColorSuitableSkin::WarmSkin->value],
                'brightness' => HairColorBrightness::Light->value,
                'temperature' => HairColorTemperature::Warm->value,
                'saturation' => HairColorSaturation::Medium->value,
                'bleach_required' => HairColorBleachRequirement::Yes->value,
                'maintenance_level' => HairColorMaintenanceLevel::Normal->value,
                'description' => '蜂蜜茶色明亮通透带一点暖金调，衬肤显气色效果突出，是浅色系发色中比较容易日常化的一款。',
                'ai_prompt' => 'honey tea brown hair color, warm golden-brown tone, sun-kissed luminous finish, light brightness with medium saturation',
                'ai_negative_prompt' => $negative,
                'seo_title' => '蜂蜜茶色发色效果图 | 通透显气色浅棕金 - HairHub',
                'seo_description' => '蜂蜜茶色通透显气色，适合黄皮、小麦色肌肤，日常染发也很百搭。用 HairHub AI换发色工具先预览蜂蜜茶色效果。',
                'is_recommended' => true,
            ],
            [
                // 分类层级修正说明：同上，“奶茶棕”本质是低饱和暖调棕色，改挂到二级分类
                // “棕色系”（自然色系下），不再挂到已改为一级分类的“浅色系”。
                'slug' => 'milk-tea-brown',
                'name' => '奶茶棕',
                'name_en' => 'Milk Tea Brown',
                'category_slug' => 'brown-tones',
                'color_hex' => '#A9805A',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value, HairColorSuitableSkin::Medium->value],
                'brightness' => HairColorBrightness::Light->value,
                'temperature' => HairColorTemperature::Warm->value,
                'saturation' => HairColorSaturation::Low->value,
                'bleach_required' => HairColorBleachRequirement::Depends->value,
                'maintenance_level' => HairColorMaintenanceLevel::Normal->value,
                'description' => '奶茶棕柔和低饱和，介于棕色和亚麻之间，温柔不挑人，是近几年长期热度很高的“万能显白棕”。',
                'ai_prompt' => 'milk tea brown hair color, soft muted warm-beige brown, gentle low-saturation tone, smooth natural finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '奶茶棕发色效果图 | 温柔百搭万能显白 - HairHub',
                'seo_description' => '奶茶棕温柔百搭，几乎适合所有肤色，是长期热度很高的万能显白色。用 HairHub AI换发色工具先预览奶茶棕上头效果。',
                'is_recommended' => true,
            ],
            [
                'slug' => 'smoky-ash-grey',
                'name' => '青木灰',
                'name_en' => 'Smoky Ash Grey',
                'category_slug' => 'grey-tones',
                'color_hex' => '#6E7375',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value, HairColorSuitableSkin::CoolSkin->value],
                'brightness' => HairColorBrightness::VeryLight->value,
                'temperature' => HairColorTemperature::Cool->value,
                'saturation' => HairColorSaturation::Low->value,
                'bleach_required' => HairColorBleachRequirement::Yes->value,
                'maintenance_level' => HairColorMaintenanceLevel::Difficult->value,
                'description' => '青木灰是低饱和度的冷调烟灰色，视觉高级且辨识度高，通常需要先漂到较浅底色才能呈现理想灰调，后期维护成本也较高。',
                'ai_prompt' => 'smoky ash grey hair color, cool desaturated grey-green undertone, soft matte finish, very light brightness',
                'ai_negative_prompt' => $negative,
                'seo_title' => '青木灰发色效果图 | 高级冷调烟灰色 - HairHub',
                'seo_description' => '青木灰高级冷调，辨识度很高，适合白皮、冷白皮肌肤。漂发风险较大，先用 HairHub AI换发色工具预览效果更放心。',
                'is_recommended' => false,
            ],
            [
                'slug' => 'smog-blue',
                'name' => '雾霾蓝',
                'name_en' => 'Smog Blue',
                'category_slug' => 'blue-tones',
                'color_hex' => '#6C8B9E',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value, HairColorSuitableSkin::CoolSkin->value],
                'brightness' => HairColorBrightness::Light->value,
                'temperature' => HairColorTemperature::Cool->value,
                'saturation' => HairColorSaturation::Medium->value,
                'bleach_required' => HairColorBleachRequirement::Yes->value,
                'maintenance_level' => HairColorMaintenanceLevel::Difficult->value,
                'description' => '雾霾蓝低饱和带灰调的冷蓝色，清冷通透辨识度极高，适合想要尝试个性发色又不想太夸张的用户，通常需要漂发才能呈现理想效果。',
                'ai_prompt' => 'smog blue hair color, muted desaturated cool blue-grey tone, soft ethereal finish, light-to-medium brightness',
                'ai_negative_prompt' => $negative,
                'seo_title' => '雾霾蓝发色效果图 | 清冷个性高辨识度 - HairHub',
                'seo_description' => '雾霾蓝清冷个性，辨识度很高，适合白皮肌肤尝鲜。染前漂发风险大，先用 HairHub AI换发色工具预览雾霾蓝效果。',
                'is_recommended' => false,
            ],
            [
                'slug' => 'rose-gold',
                'name' => '玫瑰金',
                'name_en' => 'Rose Gold',
                'category_slug' => 'gold-tones',
                'color_hex' => '#B76E79',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value, HairColorSuitableSkin::Light->value],
                'brightness' => HairColorBrightness::Light->value,
                'temperature' => HairColorTemperature::Warm->value,
                'saturation' => HairColorSaturation::Medium->value,
                'bleach_required' => HairColorBleachRequirement::Yes->value,
                'maintenance_level' => HairColorMaintenanceLevel::Difficult->value,
                'description' => '玫瑰金融合了粉调和金调，浪漫又时髦，是拍照出片效果很好的浅色系发色，通常需要漂发并定期补染才能保持理想色调。',
                'ai_prompt' => 'rose gold hair color, blended pink and warm gold undertones, luminous romantic finish, light brightness with medium saturation',
                'ai_negative_prompt' => $negative,
                'seo_title' => '玫瑰金发色效果图 | 浪漫时髦拍照出片 - HairHub',
                'seo_description' => '玫瑰金浪漫时髦，拍照效果出众，适合白皮、偏白肌肤。染前先用 HairHub AI换发色工具预览玫瑰金上头效果。',
                'is_recommended' => true,
            ],
            [
                'slug' => 'sakura-pink',
                'name' => '樱花粉',
                'name_en' => 'Sakura Pink',
                'category_slug' => 'pink-tones',
                'color_hex' => '#F4C2C2',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value],
                'brightness' => HairColorBrightness::VeryLight->value,
                'temperature' => HairColorTemperature::Neutral->value,
                'saturation' => HairColorSaturation::Low->value,
                'bleach_required' => HairColorBleachRequirement::Yes->value,
                'maintenance_level' => HairColorMaintenanceLevel::Difficult->value,
                'description' => '樱花粉是柔和的浅粉色调，甜美梦幻，通常需要先漂至很浅的底色才能呈现出理想的粉色效果，褪色速度也比较快，需要定期补染。',
                'ai_prompt' => 'sakura pink hair color, soft pastel pink tone, delicate dreamy finish, very light brightness with low saturation',
                'ai_negative_prompt' => $negative,
                'seo_title' => '樱花粉发色效果图 | 甜美梦幻少女感 - HairHub',
                'seo_description' => '樱花粉甜美梦幻，适合白皮肌肤和喜欢少女风格的用户。漂发和维护成本较高，先用 HairHub AI换发色工具预览效果。',
                'is_recommended' => false,
            ],
            [
                'slug' => 'wine-red',
                'name' => '酒红色',
                'name_en' => 'Wine Red',
                'category_slug' => 'red-tones',
                'color_hex' => '#6D1B2B',
                'suitable_skin' => [HairColorSuitableSkin::Medium->value, HairColorSuitableSkin::Tan->value, HairColorSuitableSkin::WarmSkin->value],
                'brightness' => HairColorBrightness::Dark->value,
                'temperature' => HairColorTemperature::Warm->value,
                'saturation' => HairColorSaturation::High->value,
                'bleach_required' => HairColorBleachRequirement::Depends->value,
                'maintenance_level' => HairColorMaintenanceLevel::Normal->value,
                'description' => '酒红色深邃有质感，暖调显气色，在自然光下低调，在灯光下透出红色光泽，是秋冬季节人气很高的个性发色。',
                'ai_prompt' => 'wine red hair color, deep burgundy tone with warm undertone, rich glossy finish, dark brightness with high saturation',
                'ai_negative_prompt' => $negative,
                'seo_title' => '酒红色发色效果图 | 秋冬深邃显气色 - HairHub',
                'seo_description' => '酒红色深邃显气色，适合黄皮、小麦色肌肤，秋冬季节尤其出彩。用 HairHub AI换发色工具先预览酒红色上头效果。',
                'is_recommended' => true,
            ],
            [
                'slug' => 'grape-purple',
                'name' => '葡萄紫',
                'name_en' => 'Grape Purple',
                'category_slug' => 'purple-tones',
                'color_hex' => '#5B2A5E',
                'suitable_skin' => [HairColorSuitableSkin::Fair->value, HairColorSuitableSkin::CoolSkin->value],
                'brightness' => HairColorBrightness::Dark->value,
                'temperature' => HairColorTemperature::Cool->value,
                'saturation' => HairColorSaturation::High->value,
                'bleach_required' => HairColorBleachRequirement::Yes->value,
                'maintenance_level' => HairColorMaintenanceLevel::Difficult->value,
                'description' => '葡萄紫神秘又个性，冷调高饱和，通常需要先漂发才能呈现饱满的紫色效果，褪色后会经历一段偏灰紫的过渡期，维护成本较高。',
                'ai_prompt' => 'grape purple hair color, deep cool-toned purple, rich saturated finish, dark brightness with high saturation',
                'ai_negative_prompt' => $negative,
                'seo_title' => '葡萄紫发色效果图 | 神秘个性高辨识度 - HairHub',
                'seo_description' => '葡萄紫神秘个性，辨识度很高，适合白皮肌肤尝鲜。漂发和维护成本较高，先用 HairHub AI换发色工具预览葡萄紫效果。',
                'is_recommended' => false,
            ],
        ];
    }
}
