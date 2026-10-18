<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Models\ExamSession;
use App\Models\Student;
use Illuminate\Support\Facades\Config;

/**
 * @param  list<array<string, mixed>>  $questions
 */
function personalizerSession(int $id, array $questions, ?string $order = ExamQuestionPersonalizer::ORDER_PER_STUDENT): ExamSession
{
    $config = [
        'total_questions' => count($questions),
        'seconds_per_question' => 10,
        'questions' => $questions,
    ];
    if ($order !== null) {
        $config['question_order'] = $order;
    }

    $session = new ExamSession;
    $session->id = $id;
    $session->config = $config;

    return $session;
}

function personalizerStudent(int $id): Student
{
    $student = new Student;
    $student->id = $id;

    return $student;
}

/**
 * @return list<array{word_id:int, word:string, correct_translation:string, options:list<string>}>
 */
function canonicalQuestions(int $n, int $optionsPerQuestion = 4): array
{
    $questions = [];
    for ($i = 0; $i < $n; $i++) {
        $options = ["c{$i}"];
        for ($j = 1; $j < $optionsPerQuestion; $j++) {
            $options[] = "d{$i}_{$j}";
        }
        $questions[] = [
            'word_id' => 1000 + $i,
            'word' => "w{$i}",
            'correct_translation' => "c{$i}",
            'options' => $options,
        ];
    }

    return $questions;
}

/**
 * @return list<array{question_index:int, canonical_index:int, word_id:int, word:string, options:list<string>, correct_index:int, correct_translation:string}>
 */
function fullView(ExamQuestionPersonalizer $p, ExamSession $s, Student $st): array
{
    $view = [];
    for ($i = 0, $n = $p->count($s); $i < $n; $i++) {
        $q = $p->questionAt($s, $st, $i);
        expect($q)->not->toBeNull();
        $view[] = $q;
    }

    return $view;
}

it('is deterministic for the same (session, student) across calls and instances', function (): void {
    $session = personalizerSession(42, canonicalQuestions(12));
    $student = personalizerStudent(7);

    $a = fullView(new ExamQuestionPersonalizer, $session, $student);
    $b = fullView(new ExamQuestionPersonalizer, $session, $student);

    expect($a)->toBe($b);
});

it('produces a bijection over questions and over options for every student', function (): void {
    $n = 20;
    $session = personalizerSession(1, canonicalQuestions($n));
    $p = new ExamQuestionPersonalizer;

    foreach (range(1, 25) as $studentId) {
        $view = fullView($p, $session, personalizerStudent($studentId));

        $canonical = array_column($view, 'canonical_index');
        sort($canonical);
        expect($canonical)->toBe(range(0, $n - 1));

        $wordIds = array_column($view, 'word_id');
        expect(count(array_unique($wordIds)))->toBe($n);

        foreach ($view as $q) {
            $sorted = $q['options'];
            sort($sorted);
            $expected = $session->config['questions'][$q['canonical_index']]['options'];
            sort($expected);
            expect($sorted)->toBe($expected)
                ->and($q['options'][$q['correct_index']])->toBe($q['correct_translation'])
                ->and($q['word_id'])->toBe(1000 + $q['canonical_index']);
        }
    }
});

it('gives different students different question order and option order', function (): void {
    $session = personalizerSession(5, canonicalQuestions(15));
    $p = new ExamQuestionPersonalizer;

    $orders = [];
    $optionOrders = [];
    foreach (range(1, 30) as $studentId) {
        $student = personalizerStudent($studentId);
        $orders[] = implode(',', $p->questionOrder($session, $student));

        $first = $p->questionAt($session, $student, 0);
        $optionOrders[] = $first['canonical_index'].':'.implode(',', $first['options']);
    }

    // With 15! possible orders a collision among 30 students is astronomically unlikely.
    expect(count(array_unique($orders)))->toBe(30);
    // Option order should vary too (4! = 24 possibilities, so ≥ 5 distinct across 30 is a very safe floor).
    expect(count(array_unique($optionOrders)))->toBeGreaterThanOrEqual(5);
});

it('spreads the correct option across all positions for a fixed canonical question', function (): void {
    $session = personalizerSession(9, canonicalQuestions(4));
    $p = new ExamQuestionPersonalizer;

    $positions = [];
    foreach (range(1, 200) as $studentId) {
        $student = personalizerStudent($studentId);
        foreach (range(0, 3) as $i) {
            $q = $p->questionAt($session, $student, $i);
            if ($q['canonical_index'] === 0) {
                $positions[$q['correct_index']] = ($positions[$q['correct_index']] ?? 0) + 1;
            }
        }
    }

    ksort($positions);
    expect(array_keys($positions))->toBe([0, 1, 2, 3]);
    foreach ($positions as $count) {
        expect($count)->toBeGreaterThan(20); // expected ≈ 50 each
    }
});

it('depends on the session id too (same student, different sessions)', function (): void {
    $p = new ExamQuestionPersonalizer;
    $student = personalizerStudent(3);

    $a = $p->questionOrder(personalizerSession(100, canonicalQuestions(12)), $student);
    $b = $p->questionOrder(personalizerSession(101, canonicalQuestions(12)), $student);

    expect($a)->not->toBe($b);
});

it('depends on the application key (seed is not derivable from public ids)', function (): void {
    $p = new ExamQuestionPersonalizer;
    $session = personalizerSession(100, canonicalQuestions(12));
    $student = personalizerStudent(3);

    $a = $p->questionOrder($session, $student);
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $b = $p->questionOrder($session, $student);

    expect($a)->not->toBe($b);
});

it('serves legacy sessions (no question_order) with the identity permutation', function (): void {
    $legacy = canonicalQuestions(6);
    foreach ($legacy as $i => $q) {
        $legacy[$i]['correct_index'] = 0;
    }
    $session = personalizerSession(77, $legacy, order: null);
    $p = new ExamQuestionPersonalizer;

    foreach ([personalizerStudent(1), personalizerStudent(2)] as $student) {
        expect($p->isPerStudent($session))->toBeFalse();
        expect($p->questionOrder($session, $student))->toBe(range(0, 5));
        foreach (range(0, 5) as $i) {
            $q = $p->questionAt($session, $student, $i);
            expect($q['canonical_index'])->toBe($i)
                ->and($q['options'])->toBe($legacy[$i]['options'])
                ->and($q['correct_index'])->toBe(0);
        }
    }
});

it('returns null for out-of-range indices', function (): void {
    $session = personalizerSession(1, canonicalQuestions(4));
    $p = new ExamQuestionPersonalizer;
    $student = personalizerStudent(1);

    expect($p->questionAt($session, $student, -1))->toBeNull()
        ->and($p->questionAt($session, $student, 4))->toBeNull()
        ->and($p->count($session))->toBe(4);
});

it('handles questions with fewer than 4 options', function (): void {
    $session = personalizerSession(1, canonicalQuestions(4, optionsPerQuestion: 2));
    $p = new ExamQuestionPersonalizer;

    foreach (range(1, 10) as $studentId) {
        $view = fullView($p, $session, personalizerStudent($studentId));
        foreach ($view as $q) {
            expect($q['options'])->toHaveCount(2)
                ->and($q['options'][$q['correct_index']])->toBe($q['correct_translation']);
        }
    }
});
