<?php

namespace Tests\Unit;

use App\Support\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class RobotsTxtParserTest extends TestCase
{
    public function test_valid_user_agent_group_parses_ok_and_collects_sitemaps(): void
    {
        $body = <<<'ROBOTS'
# comment
User-agent: *
Disallow: /private
Sitemap: https://example.com/sitemap.xml
Sitemap: https://example.com/news.xml
ROBOTS;

        $parsed = (new RobotsTxtParser)->parse($body, 200);

        $this->assertTrue($parsed['parse_ok']);
        $this->assertTrue($parsed['has_user_agent_group']);
        $this->assertFalse($parsed['malformed']);
        $this->assertSame(
            ['https://example.com/sitemap.xml', 'https://example.com/news.xml'],
            $parsed['sitemap_urls'],
        );
        $this->assertSame($body, $parsed['body']);
        $this->assertFalse($parsed['body_truncated']);
    }

    public function test_non_comment_text_without_user_agent_is_malformed(): void
    {
        $parsed = (new RobotsTxtParser)->parse("not a robots file\nAllow: /", 200);

        $this->assertTrue($parsed['malformed']);
        $this->assertFalse($parsed['parse_ok']);
        $this->assertFalse($parsed['has_user_agent_group']);
        $this->assertTrue($parsed['has_non_comment_text']);
    }

    public function test_empty_or_comment_only_body_is_not_malformed(): void
    {
        $parser = new RobotsTxtParser;

        $empty = $parser->parse('', 200);
        $this->assertFalse($empty['malformed']);
        $this->assertFalse($empty['parse_ok']);
        $this->assertFalse($empty['has_non_comment_text']);

        $comments = $parser->parse("# only comments\n# more\n", 200);
        $this->assertFalse($comments['malformed']);
        $this->assertFalse($comments['has_non_comment_text']);
    }

    public function test_non_200_status_skips_body_parsing(): void
    {
        $parsed = (new RobotsTxtParser)->parse("User-agent: *\nDisallow:\n", 404);

        $this->assertNull($parsed['body']);
        $this->assertFalse($parsed['parse_ok']);
        $this->assertFalse($parsed['malformed']);
        $this->assertFalse($parsed['has_user_agent_group']);
    }

    public function test_oversized_body_is_truncated(): void
    {
        $raw = str_repeat('A', RobotsTxtParser::MAX_BODY_BYTES + 50);
        $parsed = (new RobotsTxtParser)->parse($raw, 200);

        $this->assertTrue($parsed['body_truncated']);
        $this->assertSame(RobotsTxtParser::MAX_BODY_BYTES, strlen((string) $parsed['body']));
        $this->assertTrue($parsed['malformed']);
    }
}
