<?php

namespace Tests\Unit;

use App\Support\WebsitePostalAddressExtractor;
use Tests\TestCase;

class WebsitePostalAddressExtractorTest extends TestCase
{
    public function test_extracts_json_ld_and_microdata_postal_addresses(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<script type="application/ld+json">
{"@type":"LocalBusiness","address":{"@type":"PostalAddress","streetAddress":"123 Main St","addressLocality":"Austin","addressRegion":"TX","postalCode":"78701","addressCountry":"US"}}
</script>
<div itemprop="address">
  <span itemprop="streetAddress">99 Side Ave</span>
  <span itemprop="addressLocality">Austin</span>
  <span itemprop="postalCode">78702</span>
</div>
</body></html>
HTML;

        $extractor = new WebsitePostalAddressExtractor;
        $candidates = $extractor->extract($html);

        $this->assertCount(2, $candidates);
        $this->assertSame('123 Main St, Austin, TX, 78701, US', $candidates[0]['formatted']);
        $this->assertSame('99 Side Ave, Austin, 78702', $candidates[1]['formatted']);
    }

    public function test_normalize_key_compares_alphanumeric_form(): void
    {
        $extractor = new WebsitePostalAddressExtractor;

        $this->assertSame(
            '123mainstaustintx78701us',
            $extractor->normalizeKey([
                'street_address' => '123 Main St',
                'locality' => 'Austin',
                'region' => 'TX',
                'postal_code' => '78701',
                'country' => 'US',
            ]),
        );

        $this->assertSame(
            '123mainstaustintx78701us',
            $extractor->normalizeKey([
                'address_lines' => ['123 Main St'],
                'locality' => 'Austin',
                'region' => 'TX',
                'postal_code' => '78701',
                'country' => 'US',
            ]),
        );
    }
}
