<?php

declare(strict_types=1);

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Enums\StartPayloadKind;

it('parses the supported deep-link prefixes', function (string $raw, StartPayloadKind $kind, string $value, ?string $source): void {
    $payload = StartPayload::parse($raw);

    expect($payload->kind)->toBe($kind)
        ->and($payload->value)->toBe($value)
        ->and($payload->raw)->toBe($raw)
        ->and($payload->acquisitionSource())->toBe($source)
        ->and($payload->isEmpty())->toBeFalse();
})->with([
    'ref' => ['ref_ABC234', StartPayloadKind::Ref, 'ABC234', 'ref'],
    'ref longest' => ['ref_ABCDEFGHJK23', StartPayloadKind::Ref, 'ABCDEFGHJK23', 'ref'],
    'src' => ['src_tg_channel_1', StartPayloadKind::Src, 'tg_channel_1', 'src:tg_channel_1'],
    'course' => ['c_english-a1', StartPayloadKind::Course, 'english-a1', 'course:english-a1'],
    'course with underscore' => ['c_ielts_academic', StartPayloadKind::Course, 'ielts_academic', 'course:ielts_academic'],
    'org' => ['org_Center-7', StartPayloadKind::Org, 'Center-7', 'org'],
]);

it('exposes the typed parts', function (): void {
    expect(StartPayload::parse('ref_ABC234')->referralCode())->toBe('ABC234')
        ->and(StartPayload::parse('ref_ABC234')->courseSlug())->toBeNull()
        ->and(StartPayload::parse('c_english-a1')->courseSlug())->toBe('english-a1')
        ->and(StartPayload::parse('c_english-a1')->referralCode())->toBeNull();
});

it('trims the payload', function (): void {
    expect(StartPayload::parse("  src_abc \n")->raw)->toBe('src_abc');
});

it('accepts the longest valid values and rejects one character more', function (): void {
    $slug = str_repeat('a', StartPayload::COURSE_SLUG_MAX);

    expect(StartPayload::parse('c_'.$slug)->courseSlug())->toBe($slug)
        // `course:` + slug is the longest acquisition_source: it fits varchar(64).
        ->and(strlen((string) StartPayload::parse('c_'.$slug)->acquisitionSource()))->toBeLessThanOrEqual(64)
        ->and(StartPayload::parse('c_'.$slug.'a')->isEmpty())->toBeTrue()
        ->and(StartPayload::parse('src_'.str_repeat('a', 32))->isEmpty())->toBeFalse()
        ->and(StartPayload::parse('src_'.str_repeat('a', 33))->isEmpty())->toBeTrue();
});

it('turns anything else into an empty payload', function (?string $raw): void {
    $payload = StartPayload::parse($raw);

    expect($payload->isEmpty())->toBeTrue()
        ->and($payload->kind)->toBe(StartPayloadKind::None)
        ->and($payload->raw)->toBeNull()
        ->and($payload->value)->toBeNull()
        ->and($payload->acquisitionSource())->toBeNull()
        ->and($payload->referralCode())->toBeNull()
        ->and($payload->courseSlug())->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'unknown prefix' => ['hello'],
    'prefix only' => ['ref_'],
    // Phase 1 `ref_<learner id>` is gone (docs/13 §7.1): ids are guessable; only a referral code counts.
    'ref numeric id' => ['ref_42'],
    'ref lower case' => ['ref_abc234'],
    'ref too short' => ['ref_ABC23'],
    'ref too long' => ['ref_ABCDEFGHJK234'],
    'ref with dash' => ['ref_ABC-234'],
    'src upper case' => ['src_Blog'],
    'src dash' => ['src_a-b'],
    'course upper case' => ['c_Eng'],
    'course starts with dash' => ['c_-eng'],
    'dot' => ['src_a.b'],
    'space inside' => ['src_a b'],
    'newline inside' => ["src_a\nb"],
    'html' => ['<script>'],
    'sql' => ["ref_1' OR '1'='1"],
    'non-latin' => ['src_привет'],
    'longer than Telegram allows' => ['src_'.str_repeat('a', 61)],
    'case of the prefix' => ['REF_1'],
]);
