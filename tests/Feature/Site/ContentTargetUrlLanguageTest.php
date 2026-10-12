<?php

namespace Tests\Feature\Site;

use App\Services\Site\ContentPlanner;
use ReflectionMethod;

/** A new article's address follows the site's main-language pages, not the language with the most pages (opcklinik.com). */
final class ContentTargetUrlLanguageTest extends SiteTestCase
{
    public function test_a_turkish_article_does_not_get_the_prefix_of_the_sites_other_languages(): void
    {
        $this->page('/', 'Ana sayfa');
        $this->page('/blog/implant-sonrasi/', 'İmplant sonrası', ['wp_post_type' => 'post']);
        foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
            $this->page('/de/blog/'.$slug.'/', 'Beitrag '.$slug, ['language' => 'de', 'wp_post_type' => 'post']);
        }

        $planner = app(ContentPlanner::class);
        $pages = (new ReflectionMethod($planner, 'sitePages'))->invoke($planner, $this->site);
        $this->assertSame('tr', $pages->first()->language, 'main-language pages come first in the 300');
        $pattern = (new ReflectionMethod(ContentPlanner::class, 'urlPattern'))->invoke(null, $this->site, $pages);

        $url = $pattern->targetUrl('https://panorama.com.tr', 'guide', '20-lik-dis');
        $this->assertStringNotContainsString('/de/', $url);
        $this->assertStringEndsWith('/20-lik-dis/', $url);
    }
}
