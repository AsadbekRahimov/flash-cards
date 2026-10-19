<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Models\ExamSession;
use App\Models\Learner;
use Illuminate\Support\Facades\Config;

/**
 * @param  list<array<string, mixed>>  $questions
 */
function personalizerSession(int $id, array $questions, ?string $order = ExamQuestionPersonalizer::ORDER_PER_LEARNER): ExamSession
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

function personalizerLearner(int $id): Learner
{
    $learner = new Learner;
    $learner->id = $id;

    return $learner;
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
function fullView(ExamQuestionPersonalizer $p, ExamSession $s, Learner $st): array
{
    $view = [];
    for ($i = 0, $n = $p->count($s); $i < $n; $i++) {
        $q = $p->questionAt($s, $st, $i);
        expect($q)->not->toBeNull();
        $view[] = $q;
    }

    return $view;
}

it('is deterministic for the same (session, learner) across calls and instances', function (): void {
    $session = personalizerSession(42, canonicalQuestions(12));
    $learner = personalizerLearner(7);

    $a = fullView(new ExamQuestionPersonalizer, $session, $learner);
    $b = fullView(new ExamQuestionPersonalizer, $session, $learner);

    expect($a)->toBe($b);
});

it('produces a bijection over questions and over options for every learner', function (): void {
    $n = 20;
    $session = personalizerSession(1, canonicalQuestions($n));
    $p = new ExamQuestionPersonalizer;

    foreach (range(1, 25) as $learnerId) {
        $view = fullView($p, $session, personalizerLearner($learnerId));

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

it('gives different learners different question order and option order', function (): void {
    $session = personalizerSession(5, canonicalQuestions(15));
    $p = new ExamQuestionPersonalizer;

    $orders = [];
    $optionOrders = [];
    foreach (range(1, 30) as $learnerId) {
        $learner = personalizerLearner($learnerId);
        $orders[] = implode(',', $p->questionOrder($session, $learner));

        $first = $p->questionAt($session, $learner, 0);
        $optionOrders[] = $first['canonical_index'].':'.implode(',', $first['options']);
    }

    // With 15! possible orders a collision among 30 learners is astronomically unlikely.
    expect(count(array_unique($orders)))->toBe(30);
    // Option order should vary too (4! = 24 possibilities, so ≥ 5 distinct across 30 is a very safe floor).
    expect(count(array_unique($optionOrders)))->toBeGreaterThanOrEqual(5);
});

it('spreads the correct option across all positions for a fixed canonical question', function (): void {
    $session = personalizerSession(9, canonicalQuestions(4));
    $p = new ExamQuestionPersonalizer;

    $positions = [];
    foreach (range(1, 200) as $learnerId) {
        $learner = personalizerLearner($learnerId);
        foreach (range(0, 3) as $i) {
            $q = $p->questionAt($session, $learner, $i);
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

it('builds its seed from (session_id, learner_id) keyed with the order_secret', function (): void {
    $session = personalizerSession(100, canonicalQuestions(9));
    $session->config = [...$session->config, 'order_secret' => 'fixed-secret'];

    // Independent re-implementation of the documented derivation (docs/12 §3.3).
    $seed = hash_hmac('sha256', 'exam-order|100|7', 'fixed-secret', true);
    $keys = [];
    foreach (range(0, 8) as $i) {
        $keys[$i] = hash_hmac('sha256', "q|{$i}", $seed, true);
    }
    $expected = range(0, 8);
    usort($expected, static fn (int $a, int $b): int => ($keys[$a] <=> $keys[$b]) ?: ($a <=> $b));

    expect((new ExamQuestionPersonalizer)->questionOrder($session, personalizerLearner(7)))->toBe($expected);
});

it('ignores every learner attribute except the id (names, locale, groups never enter the seed)', function (): void {
    $p = new ExamQuestionPersonalizer;
    $session = personalizerSession(100, canonicalQuestions(12));

    $bare = personalizerLearner(7);
    $decorated = personalizerLearner(7);
    $decorated->first_name = 'Alice';
    $decorated->telegram_user_id = 123456;
    $decorated->interface_locale = 'uz';

    expect($p->questionOrder($session, $decorated))->toBe($p->questionOrder($session, $bare))
        ->and(fullView($p, $session, $decorated))->toBe(fullView($p, $session, $bare));
});

it('depends on the session id too (same learner, different sessions)', function (): void {
    $p = new ExamQuestionPersonalizer;
    $learner = personalizerLearner(3);

    $a = $p->questionOrder(personalizerSession(100, canonicalQuestions(12)), $learner);
    $b = $p->questionOrder(personalizerSession(101, canonicalQuestions(12)), $learner);

    expect($a)->not->toBe($b);
});

it('depends on the application key (seed is not derivable from public ids)', function (): void {
    $p = new ExamQuestionPersonalizer;
    $session = personalizerSession(100, canonicalQuestions(12));
    $learner = personalizerLearner(3);

    $a = $p->questionOrder($session, $learner);
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $b = $p->questionOrder($session, $learner);

    expect($a)->not->toBe($b);
});

it('keeps the order stable when app.key is rotated if the session has an order_secret', function (): void {
    $p = new ExamQuestionPersonalizer;
    $session = personalizerSession(100, canonicalQuestions(12));
    $session->config = [...$session->config, 'order_secret' => bin2hex(random_bytes(32))];
    $learner = personalizerLearner(3);

    $before = fullView($p, $session, $learner);
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $after = fullView($p, $session, $learner);

    expect($after)->toBe($before);
});

it('derives different orders from different order_secrets and stays deterministic per secret', function (): void {
    $p = new ExamQuestionPersonalizer;
    $learner = personalizerLearner(3);
    $make = function (string $secret): ExamSession {
        $session = personalizerSession(100, canonicalQuestions(12));
        $session->config = [...$session->config, 'order_secret' => $secret];

        return $session;
    };

    $a = $p->questionOrder($make('secret-a'), $learner);

    expect($p->questionOrder($make('secret-a'), $learner))->toBe($a)
        ->and($p->questionOrder($make('secret-b'), $learner))->not->toBe($a);
});

it('still gives different learners different orders with an order_secret', function (): void {
    $p = new ExamQuestionPersonalizer;
    $session = personalizerSession(100, canonicalQuestions(12));
    $session->config = [...$session->config, 'order_secret' => bin2hex(random_bytes(32))];

    expect($p->questionOrder($session, personalizerLearner(1)))
        ->not->toBe($p->questionOrder($session, personalizerLearner(2)));
});

it('falls back to app.key for sessions created before order_secret existed', function (): void {
    $p = new ExamQuestionPersonalizer;
    $session = personalizerSession(100, canonicalQuestions(12));
    $learner = personalizerLearner(3);

    $before = $p->questionOrder($session, $learner);
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    expect($p->questionOrder($session, $learner))->not->toBe($before);
});

it('serves legacy sessions (no question_order) with the identity permutation', function (): void {
    $legacy = canonicalQuestions(6);
    foreach ($legacy as $i => $q) {
        $legacy[$i]['correct_index'] = 0;
    }
    $session = personalizerSession(77, $legacy, order: null);
    $p = new ExamQuestionPersonalizer;

    foreach ([personalizerLearner(1), personalizerLearner(2)] as $learner) {
        expect($p->isPerLearner($session))->toBeFalse();
        expect($p->questionOrder($session, $learner))->toBe(range(0, 5));
        foreach (range(0, 5) as $i) {
            $q = $p->questionAt($session, $learner, $i);
            expect($q['canonical_index'])->toBe($i)
                ->and($q['options'])->toBe($legacy[$i]['options'])
                ->and($q['correct_index'])->toBe(0);
        }
    }
});

it('returns null for out-of-range indices', function (): void {
    $session = personalizerSession(1, canonicalQuestions(4));
    $p = new ExamQuestionPersonalizer;
    $learner = personalizerLearner(1);

    expect($p->questionAt($session, $learner, -1))->toBeNull()
        ->and($p->questionAt($session, $learner, 4))->toBeNull()
        ->and($p->count($session))->toBe(4);
});

it('handles questions with fewer than 4 options', function (): void {
    $session = personalizerSession(1, canonicalQuestions(4, optionsPerQuestion: 2));
    $p = new ExamQuestionPersonalizer;

    foreach (range(1, 10) as $learnerId) {
        $view = fullView($p, $session, personalizerLearner($learnerId));
        foreach ($view as $q) {
            expect($q['options'])->toHaveCount(2)
                ->and($q['options'][$q['correct_index']])->toBe($q['correct_translation']);
        }
    }
});
