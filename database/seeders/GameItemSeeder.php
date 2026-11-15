<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Enums\ItemAvailability;
use App\Domain\Game\Enums\ItemKind;
use App\Domain\Game\Enums\ItemSlot;
use App\Domain\Game\Enums\ItemStatus;
use App\Domain\Game\Support\GameAssets;
use App\Models\GameItem;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * The base catalogue of the shop of the game "Caravan" (docs/18 §12.2.6, G2.5b): 3 camels, 2 carpets, 2 tents, 2 frames, 3 boosters.
 * Idempotent by `key`, and it only ADDS: an item that exists is left as the admin panel made it (price, texts, status), so running
 * the seeder again never undoes an edit. Production runs it once: `php artisan db:seed --class=GameItemSeeder`.
 *
 * Prices are set against the economy of docs/18 §12.5.3: a stop pays 10 coins and 5 for each new star (up to 25), a boss 50, the daily
 * caravan 10 + 5 per star. So a first camel costs about 6 clearings, a booster 1-2. One cosmetic of every slot costs 0 and belongs to
 * everybody (a cosmetic, `base`, price 0: owned without a row, `ShopService`); the golden camel is `exclusive` (Pro at the moment of the purchase).
 */
class GameItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->items() as $sort => $item) {
            if (! GameAssets::has($item['asset'])) {
                throw new LogicException("The asset {$item['asset']} of {$item['key']} is not in the whitelist GameAssets::NAMES.");
            }

            GameItem::query()->firstOrCreate(['key' => $item['key']], [
                'kind' => $item['slot'] !== null ? ItemKind::Cosmetic : ItemKind::Booster,
                'slot' => $item['slot'],
                'effect' => $item['effect'],
                'price_coins' => $item['price'],
                'availability' => $item['availability'],
                'status' => ItemStatus::Published,
                'sort' => ($sort + 1) * 10,
                'title' => $item['title'],
                'description' => $item['description'],
                'asset' => $item['asset'],
            ]);
        }
    }

    /**
     * @return list<array{key: string, slot: ItemSlot|null, effect: BoosterEffect|null, price: int, availability: ItemAvailability, title: array{ru: string, uz: string}, description: array{ru: string, uz: string}, asset: string}>
     */
    private function items(): array
    {
        $base = ItemAvailability::Base;

        return [
            ['key' => 'camel_dromedary', 'slot' => ItemSlot::Camel, 'effect' => null, 'price' => 0, 'availability' => $base,
                'title' => ['ru' => 'Дромедар', 'uz' => 'Dromedar'], 'description' => ['ru' => 'Одногорбый верный спутник каждого каравана.', 'uz' => 'Har bir karvonning bir o\'rkachli sodiq yo\'ldoshi.'], 'asset' => 'camel_dromedary'],
            ['key' => 'camel_bactrian', 'slot' => ItemSlot::Camel, 'effect' => null, 'price' => 150, 'availability' => $base,
                'title' => ['ru' => 'Бактриан', 'uz' => 'Baqtriyon'], 'description' => ['ru' => 'Двугорбый, с густой шерстью: не боится ни пустынь, ни перевалов.', 'uz' => 'Ikki o\'rkachli, qalin junli: na cho\'ldan, na dovondan qo\'rqadi.'], 'asset' => 'camel_bactrian'],
            ['key' => 'camel_golden', 'slot' => ItemSlot::Camel, 'effect' => null, 'price' => 400, 'availability' => ItemAvailability::Exclusive,
                'title' => ['ru' => 'Золотой верблюд', 'uz' => 'Oltin tuya'], 'description' => ['ru' => 'Верблюд эмира: только для Pro.', 'uz' => 'Amir tuyasi: faqat Pro uchun.'], 'asset' => 'camel_golden'],
            ['key' => 'carpet_plain', 'slot' => ItemSlot::Carpet, 'effect' => null, 'price' => 0, 'availability' => $base,
                'title' => ['ru' => 'Простой ковёр', 'uz' => 'Oddiy gilam'], 'description' => ['ru' => 'Войлочный коврик на первую стоянку.', 'uz' => 'Birinchi bekat uchun namat.'], 'asset' => 'carpet_plain'],
            ['key' => 'carpet_bukhara', 'slot' => ItemSlot::Carpet, 'effect' => null, 'price' => 80, 'availability' => $base,
                'title' => ['ru' => 'Бухарский ковёр', 'uz' => 'Buxoro gilami'], 'description' => ['ru' => 'Узор, который ткали сто лет.', 'uz' => 'Yuz yil to\'qilgan naqsh.'], 'asset' => 'carpet_bukhara'],
            ['key' => 'tent_nomad', 'slot' => ItemSlot::Tent, 'effect' => null, 'price' => 0, 'availability' => $base,
                'title' => ['ru' => 'Шатёр кочевника', 'uz' => 'Ko\'chmanchi chodiri'], 'description' => ['ru' => 'Лёгкий шатёр: разбить и свернуть за минуту.', 'uz' => 'Yengil chodir: bir daqiqada tikiladi va yig\'iladi.'], 'asset' => 'tent_nomad'],
            ['key' => 'tent_silk', 'slot' => ItemSlot::Tent, 'effect' => null, 'price' => 100, 'availability' => $base,
                'title' => ['ru' => 'Шёлковый шатёр', 'uz' => 'Ipak chodir'], 'description' => ['ru' => 'Шатёр купца с шёлковым пологом.', 'uz' => 'Ipak yopinchiqli savdogar chodiri.'], 'asset' => 'tent_silk'],
            ['key' => 'frame_plain', 'slot' => ItemSlot::Frame, 'effect' => null, 'price' => 0, 'availability' => $base,
                'title' => ['ru' => 'Простая рамка', 'uz' => 'Oddiy ramka'], 'description' => ['ru' => 'Тонкая рамка для портрета.', 'uz' => 'Portret uchun ingichka ramka.'], 'asset' => 'frame_plain'],
            ['key' => 'frame_ornament', 'slot' => ItemSlot::Frame, 'effect' => null, 'price' => 60, 'availability' => $base,
                'title' => ['ru' => 'Рамка с орнаментом', 'uz' => 'Naqshli ramka'], 'description' => ['ru' => 'Восточный орнамент по краю.', 'uz' => 'Chetida sharqona naqsh.'], 'asset' => 'frame_ornament'],
            ['key' => 'booster_hint', 'slot' => null, 'effect' => BoosterEffect::Hint, 'price' => 15, 'availability' => $base,
                'title' => ['ru' => 'Подсказка', 'uz' => 'Maslahat'], 'description' => ['ru' => 'Убирает два неверных варианта или открывает первые две буквы.', 'uz' => 'Ikkita noto\'g\'ri variantni olib tashlaydi yoki dastlabki ikki harfni ochadi.'], 'asset' => 'booster_hint'],
            ['key' => 'booster_extra_flask', 'slot' => null, 'effect' => BoosterEffect::ExtraFlask, 'price' => 30, 'availability' => $base,
                'title' => ['ru' => 'Запасная фляга', 'uz' => 'Zaxira mesh'], 'description' => ['ru' => 'Плюс одна фляга в забеге: ещё одна ошибка не страшна.', 'uz' => 'Safarda yana bitta mesh: yana bir marta xato qilsangiz ham bo\'ladi.'], 'asset' => 'booster_extra_flask'],
            ['key' => 'booster_second_chance', 'slot' => null, 'effect' => BoosterEffect::SecondChance, 'price' => 40, 'availability' => $base,
                'title' => ['ru' => 'Второй шанс', 'uz' => 'Ikkinchi imkon'], 'description' => ['ru' => 'Первая ошибка забега не стоит фляги.', 'uz' => 'Safardagi birinchi xato uchun mesh yo\'qolmaydi.'], 'asset' => 'booster_second_chance'],
        ];
    }
}
