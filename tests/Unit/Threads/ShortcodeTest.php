<?php

namespace Tests\Unit\Threads;

use App\Threads\Shortcode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShortcodeTest extends TestCase
{
    /** Synthetic shortcodes with their decoded pk and creation time (algorithm: docs/HOW-IT-WORKS.md §7). */
    public static function knownPosts(): array
    {
        return [
            ['DFakeP0st01', '3556315264011394357', '2025-01-29 15:50:37.762'],
            ['DFakeP0st02', '3556315264011394358', '2025-01-29 15:50:37.762'],
            ['CFake_Old01', '2403393810304720181', '2020-09-21 22:21:30.358'],
            ['DeFake-Now1', '4000720700355021877', '2026-10-04 19:44:54.101'],
            ['DFakeShr0x9', '3556315266913619069', '2025-01-29 15:50:38.108'],
        ];
    }

    #[DataProvider('knownPosts')]
    public function test_decodes_pk_matching_page_pk(string $code, string $pk, string $created): void
    {
        $this->assertSame($pk, Shortcode::toPk($code));
    }

    #[DataProvider('knownPosts')]
    public function test_created_at_is_utc_with_millisecond_precision(string $code, string $pk, string $created): void
    {
        $at = Shortcode::createdAt($code);
        $this->assertSame('UTC', $at->getTimezone()->getName());
        $this->assertSame($created, $at->format('Y-m-d H:i:s.v'));
    }

    public function test_rejects_invalid_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Shortcode::toPk('abc$def');
    }

    public function test_rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Shortcode::toPk('');
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Shortcode::isValid('DFakeP0st01'));
        $this->assertFalse(Shortcode::isValid('abc'));
        $this->assertFalse(Shortcode::isValid('abc/def12'));
    }
}
