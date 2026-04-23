<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoContentSeeder extends Seeder
{
    /** @var array<int, array<int, array<int, array{word:string,translation:string,pos:string}>>> */
    private const CONTENT = [
        1 => [ // Stage 1 — Beginner
            1 => [
                ['word' => 'hello', 'translation' => 'привет', 'pos' => 'interjection'],
                ['word' => 'world', 'translation' => 'мир', 'pos' => 'noun'],
                ['word' => 'name', 'translation' => 'имя', 'pos' => 'noun'],
                ['word' => 'friend', 'translation' => 'друг', 'pos' => 'noun'],
                ['word' => 'family', 'translation' => 'семья', 'pos' => 'noun'],
                ['word' => 'house', 'translation' => 'дом', 'pos' => 'noun'],
                ['word' => 'water', 'translation' => 'вода', 'pos' => 'noun'],
                ['word' => 'food', 'translation' => 'еда', 'pos' => 'noun'],
                ['word' => 'good', 'translation' => 'хороший', 'pos' => 'adjective'],
                ['word' => 'bad', 'translation' => 'плохой', 'pos' => 'adjective'],
                ['word' => 'big', 'translation' => 'большой', 'pos' => 'adjective'],
                ['word' => 'small', 'translation' => 'маленький', 'pos' => 'adjective'],
                ['word' => 'go', 'translation' => 'идти', 'pos' => 'verb'],
                ['word' => 'come', 'translation' => 'приходить', 'pos' => 'verb'],
                ['word' => 'see', 'translation' => 'видеть', 'pos' => 'verb'],
                ['word' => 'eat', 'translation' => 'есть', 'pos' => 'verb'],
                ['word' => 'drink', 'translation' => 'пить', 'pos' => 'verb'],
                ['word' => 'sleep', 'translation' => 'спать', 'pos' => 'verb'],
                ['word' => 'red', 'translation' => 'красный', 'pos' => 'adjective'],
                ['word' => 'blue', 'translation' => 'синий', 'pos' => 'adjective'],
            ],
            2 => [
                ['word' => 'morning', 'translation' => 'утро', 'pos' => 'noun'],
                ['word' => 'evening', 'translation' => 'вечер', 'pos' => 'noun'],
                ['word' => 'night', 'translation' => 'ночь', 'pos' => 'noun'],
                ['word' => 'day', 'translation' => 'день', 'pos' => 'noun'],
                ['word' => 'week', 'translation' => 'неделя', 'pos' => 'noun'],
                ['word' => 'month', 'translation' => 'месяц', 'pos' => 'noun'],
                ['word' => 'year', 'translation' => 'год', 'pos' => 'noun'],
                ['word' => 'time', 'translation' => 'время', 'pos' => 'noun'],
                ['word' => 'monday', 'translation' => 'понедельник', 'pos' => 'noun'],
                ['word' => 'tuesday', 'translation' => 'вторник', 'pos' => 'noun'],
                ['word' => 'wednesday', 'translation' => 'среда', 'pos' => 'noun'],
                ['word' => 'thursday', 'translation' => 'четверг', 'pos' => 'noun'],
                ['word' => 'friday', 'translation' => 'пятница', 'pos' => 'noun'],
                ['word' => 'saturday', 'translation' => 'суббота', 'pos' => 'noun'],
                ['word' => 'sunday', 'translation' => 'воскресенье', 'pos' => 'noun'],
                ['word' => 'today', 'translation' => 'сегодня', 'pos' => 'adverb'],
                ['word' => 'tomorrow', 'translation' => 'завтра', 'pos' => 'adverb'],
                ['word' => 'yesterday', 'translation' => 'вчера', 'pos' => 'adverb'],
                ['word' => 'now', 'translation' => 'сейчас', 'pos' => 'adverb'],
                ['word' => 'later', 'translation' => 'позже', 'pos' => 'adverb'],
            ],
            3 => [
                ['word' => 'mother', 'translation' => 'мать', 'pos' => 'noun'],
                ['word' => 'father', 'translation' => 'отец', 'pos' => 'noun'],
                ['word' => 'sister', 'translation' => 'сестра', 'pos' => 'noun'],
                ['word' => 'brother', 'translation' => 'брат', 'pos' => 'noun'],
                ['word' => 'son', 'translation' => 'сын', 'pos' => 'noun'],
                ['word' => 'daughter', 'translation' => 'дочь', 'pos' => 'noun'],
                ['word' => 'parent', 'translation' => 'родитель', 'pos' => 'noun'],
                ['word' => 'child', 'translation' => 'ребёнок', 'pos' => 'noun'],
                ['word' => 'baby', 'translation' => 'малыш', 'pos' => 'noun'],
                ['word' => 'wife', 'translation' => 'жена', 'pos' => 'noun'],
                ['word' => 'husband', 'translation' => 'муж', 'pos' => 'noun'],
                ['word' => 'uncle', 'translation' => 'дядя', 'pos' => 'noun'],
                ['word' => 'aunt', 'translation' => 'тётя', 'pos' => 'noun'],
                ['word' => 'cousin', 'translation' => 'кузен', 'pos' => 'noun'],
                ['word' => 'grandfather', 'translation' => 'дедушка', 'pos' => 'noun'],
                ['word' => 'grandmother', 'translation' => 'бабушка', 'pos' => 'noun'],
                ['word' => 'family', 'translation' => 'семья', 'pos' => 'noun'],
                ['word' => 'love', 'translation' => 'любовь', 'pos' => 'noun'],
                ['word' => 'home', 'translation' => 'дом', 'pos' => 'noun'],
                ['word' => 'together', 'translation' => 'вместе', 'pos' => 'adverb'],
            ],
        ],
        2 => [ // Stage 2 — Elementary
            1 => [
                ['word' => 'work', 'translation' => 'работа', 'pos' => 'noun'],
                ['word' => 'office', 'translation' => 'офис', 'pos' => 'noun'],
                ['word' => 'computer', 'translation' => 'компьютер', 'pos' => 'noun'],
                ['word' => 'phone', 'translation' => 'телефон', 'pos' => 'noun'],
                ['word' => 'email', 'translation' => 'эл. почта', 'pos' => 'noun'],
                ['word' => 'meeting', 'translation' => 'встреча', 'pos' => 'noun'],
                ['word' => 'project', 'translation' => 'проект', 'pos' => 'noun'],
                ['word' => 'client', 'translation' => 'клиент', 'pos' => 'noun'],
                ['word' => 'team', 'translation' => 'команда', 'pos' => 'noun'],
                ['word' => 'manager', 'translation' => 'менеджер', 'pos' => 'noun'],
                ['word' => 'boss', 'translation' => 'начальник', 'pos' => 'noun'],
                ['word' => 'salary', 'translation' => 'зарплата', 'pos' => 'noun'],
                ['word' => 'task', 'translation' => 'задача', 'pos' => 'noun'],
                ['word' => 'deadline', 'translation' => 'срок', 'pos' => 'noun'],
                ['word' => 'report', 'translation' => 'отчёт', 'pos' => 'noun'],
                ['word' => 'finish', 'translation' => 'закончить', 'pos' => 'verb'],
                ['word' => 'start', 'translation' => 'начать', 'pos' => 'verb'],
                ['word' => 'send', 'translation' => 'отправить', 'pos' => 'verb'],
                ['word' => 'receive', 'translation' => 'получить', 'pos' => 'verb'],
                ['word' => 'discuss', 'translation' => 'обсуждать', 'pos' => 'verb'],
            ],
            2 => [
                ['word' => 'travel', 'translation' => 'путешествие', 'pos' => 'noun'],
                ['word' => 'airport', 'translation' => 'аэропорт', 'pos' => 'noun'],
                ['word' => 'flight', 'translation' => 'рейс', 'pos' => 'noun'],
                ['word' => 'ticket', 'translation' => 'билет', 'pos' => 'noun'],
                ['word' => 'passport', 'translation' => 'паспорт', 'pos' => 'noun'],
                ['word' => 'luggage', 'translation' => 'багаж', 'pos' => 'noun'],
                ['word' => 'hotel', 'translation' => 'отель', 'pos' => 'noun'],
                ['word' => 'room', 'translation' => 'комната', 'pos' => 'noun'],
                ['word' => 'beach', 'translation' => 'пляж', 'pos' => 'noun'],
                ['word' => 'mountain', 'translation' => 'гора', 'pos' => 'noun'],
                ['word' => 'city', 'translation' => 'город', 'pos' => 'noun'],
                ['word' => 'country', 'translation' => 'страна', 'pos' => 'noun'],
                ['word' => 'map', 'translation' => 'карта', 'pos' => 'noun'],
                ['word' => 'tourist', 'translation' => 'турист', 'pos' => 'noun'],
                ['word' => 'guide', 'translation' => 'гид', 'pos' => 'noun'],
                ['word' => 'visit', 'translation' => 'посещать', 'pos' => 'verb'],
                ['word' => 'arrive', 'translation' => 'прибывать', 'pos' => 'verb'],
                ['word' => 'leave', 'translation' => 'покидать', 'pos' => 'verb'],
                ['word' => 'book', 'translation' => 'бронировать', 'pos' => 'verb'],
                ['word' => 'pack', 'translation' => 'упаковывать', 'pos' => 'verb'],
            ],
            3 => [
                ['word' => 'restaurant', 'translation' => 'ресторан', 'pos' => 'noun'],
                ['word' => 'menu', 'translation' => 'меню', 'pos' => 'noun'],
                ['word' => 'waiter', 'translation' => 'официант', 'pos' => 'noun'],
                ['word' => 'bill', 'translation' => 'счёт', 'pos' => 'noun'],
                ['word' => 'tip', 'translation' => 'чаевые', 'pos' => 'noun'],
                ['word' => 'breakfast', 'translation' => 'завтрак', 'pos' => 'noun'],
                ['word' => 'lunch', 'translation' => 'обед', 'pos' => 'noun'],
                ['word' => 'dinner', 'translation' => 'ужин', 'pos' => 'noun'],
                ['word' => 'soup', 'translation' => 'суп', 'pos' => 'noun'],
                ['word' => 'salad', 'translation' => 'салат', 'pos' => 'noun'],
                ['word' => 'meat', 'translation' => 'мясо', 'pos' => 'noun'],
                ['word' => 'fish', 'translation' => 'рыба', 'pos' => 'noun'],
                ['word' => 'bread', 'translation' => 'хлеб', 'pos' => 'noun'],
                ['word' => 'cheese', 'translation' => 'сыр', 'pos' => 'noun'],
                ['word' => 'coffee', 'translation' => 'кофе', 'pos' => 'noun'],
                ['word' => 'tea', 'translation' => 'чай', 'pos' => 'noun'],
                ['word' => 'order', 'translation' => 'заказывать', 'pos' => 'verb'],
                ['word' => 'taste', 'translation' => 'пробовать', 'pos' => 'verb'],
                ['word' => 'cook', 'translation' => 'готовить', 'pos' => 'verb'],
                ['word' => 'serve', 'translation' => 'подавать', 'pos' => 'verb'],
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::CONTENT as $stageNumber => $lessons) {
                $stage = Stage::updateOrCreate(
                    ['number' => $stageNumber],
                    [
                        'title' => "Stage {$stageNumber}",
                        'description' => "Demo stage {$stageNumber}",
                    ],
                );

                foreach ($lessons as $lessonNumber => $words) {
                    $lesson = Lesson::updateOrCreate(
                        ['stage_id' => $stage->id, 'number' => $lessonNumber],
                        ['title' => "Lesson {$lessonNumber}"],
                    );

                    foreach ($words as $w) {
                        Word::updateOrCreate(
                            ['lesson_id' => $lesson->id, 'word' => $w['word']],
                            [
                                'translation' => $w['translation'],
                                'part_of_speech' => $w['pos'],
                                'meta' => [],
                            ],
                        );
                    }
                }
            }
        });
    }
}
