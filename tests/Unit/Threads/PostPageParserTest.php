<?php

namespace Tests\Unit\Threads;

use App\Threads\Exceptions\ParseException;
use App\Threads\PostPageParser;
use App\Threads\Shortcode;
use PHPUnit\Framework\TestCase;

class PostPageParserTest extends TestCase
{
    private const CODE = 'DFakeP0st01';

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/'.$name);
    }

    public function test_parses_all_metrics_from_main_post_not_reply(): void
    {
        $d = (new PostPageParser)->parse($this->fixture('post_ok.html'), self::CODE);

        $this->assertSame(self::CODE, $d->shortcode);
        $this->assertSame(Shortcode::toPk(self::CODE), $d->pk);
        $this->assertSame('example_user', $d->username);
        $this->assertSame(1791000000, $d->takenAt->getTimestamp());
        $this->assertSame('UTC', $d->takenAt->getTimezone()->getName());
        $this->assertSame(13410, $d->views);
        $this->assertSame(321, $d->likes); // reply in same payload has 5
        $this->assertSame(17, $d->replies);
        $this->assertSame(4, $d->reposts);
        $this->assertSame(2, $d->quotes);
        $this->assertSame(9, $d->shares);
        $this->assertFalse($d->countsHidden);
    }

    public function test_null_shares_stay_null_not_zero(): void
    {
        $d = (new PostPageParser)->parse($this->fixture('post_null_shares.html'), self::CODE);

        $this->assertNull($d->shares);
        $this->assertSame(250, $d->views);
        $this->assertSame(17, $d->replies);
    }

    public function test_counts_hidden_by_author_nulls_likes_and_views(): void
    {
        $d = (new PostPageParser)->parse($this->fixture('post_counts_hidden.html'), self::CODE);

        $this->assertTrue($d->countsHidden);
        $this->assertNull($d->likes);
        $this->assertNull($d->views);
        $this->assertSame(17, $d->replies);
    }

    public function test_missing_views_config_gives_null_views(): void
    {
        $d = (new PostPageParser)->parse($this->fixture('post_missing_views.html'), self::CODE);

        $this->assertNull($d->views); // decoy "view_counts":999999 in another config must be ignored
        $this->assertSame(321, $d->likes);
    }

    public function test_throws_when_post_object_not_found(): void
    {
        try {
            (new PostPageParser)->parse($this->fixture('page_without_post.html'), self::CODE);
            $this->fail('Expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame(ParseException::POST_NOT_FOUND, $e->reason);
        }
    }

    public function test_throws_for_other_shortcode(): void
    {
        $this->expectException(ParseException::class);
        (new PostPageParser)->parse($this->fixture('post_ok.html'), 'DOtherC0de9');
    }

    public function test_throws_on_empty_body(): void
    {
        $this->expectException(ParseException::class);
        (new PostPageParser)->parse("  \n", self::CODE);
    }

    public function test_views_regex_reads_gating_config_only(): void
    {
        $p = new PostPageParser;

        $this->assertSame(6433, $p->extractViews('x,["BarcelonaLoggedOutExpansionGating",[],{"enable_nav":true,"enable_view_counts":false,"view_counts":6433},123]'));
        $this->assertNull($p->extractViews('["OtherGating",[],{"view_counts":6433}]'));
        // Must not cross into a nested object (would risk reading another config's value).
        $this->assertNull($p->extractViews('["BarcelonaLoggedOutExpansionGating",[],{"a":{"b":1},"view_counts":5}]'));
        $this->assertNull($p->extractViews('["BarcelonaLoggedOutExpansionGating",[],{"view_counts":null}]'));
    }

    public function test_reads_og_content_decoded(): void
    {
        $p = new PostPageParser;
        $html = $this->fixture('post_ok.html');

        $this->assertSame('https://www.threads.com/@example_user/post/'.self::CODE, $p->ogContent($html, 'url'));
        $this->assertSame('Example User (@example_user) on Threads', $p->ogContent($html, 'title'));
        $this->assertNull($p->ogContent($this->fixture('login_wall.html'), 'description'));
    }

    // ---- K2: caption ----

    public function test_caption_text_is_read_from_main_post_with_line_breaks(): void
    {
        $d = (new PostPageParser)->parse($this->fixture('post_with_caption.html'), self::CODE);

        $this->assertSame("假的測試內文第一行\n第二行 <b>not bold</b> & 100% done_\n\n#假標籤 結尾", $d->caption);
        $this->assertSame(321, $d->likes);
        $this->assertSame($d->caption, $d->toArray()['caption']);
    }

    public function test_caption_null_or_without_text_gives_null(): void
    {
        $html = $this->fixture('post_with_caption.html');
        $main = '"caption":{"has_translation":false,"pk":"17900000000000001","text":"假的測試內文第一行\\n第二行 <b>not bold</b> & 100% done_\\n\\n#假標籤 結尾","text_translation":null}';
        $this->assertStringContainsString($main, $html);

        foreach (['"caption":null', '"caption":{"has_translation":false,"pk":"1"}', '"caption":{"text":null}', '"caption":{"text":"   "}', '"caption":"oops"'] as $replacement) {
            $d = (new PostPageParser)->parse(str_replace($main, $replacement, $html), self::CODE);
            $this->assertNull($d->caption, $replacement);
        }

        // No caption key at all
        $d = (new PostPageParser)->parse(str_replace($main.',', '', $html), self::CODE);
        $this->assertNull($d->caption);
    }
}
