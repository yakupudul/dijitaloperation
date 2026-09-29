<?php

namespace App\Services\Site\Backlinks;

use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Services\Site\SiteDomains;
use App\Services\Website\PageFetcher;
use DOMDocument;
use Throwable;

/**
 * Link check of a potential source the operator marked "verildi": the link page (else the source URL) is fetched with the
 * safe public fetcher and searched for an <a href> to one of the brand's domains. Found → doğrulandı. Missing on a
 * doğrulandı source → back to "yok" with the note "kaldırıldı". Missing on a verildi source stays verildi with a note.
 * An unreachable page changes nothing but the note. Runs on marking and weekly.
 */
final class BacklinkVerifier
{
    public const string FOUND = 'found';

    public const string MISSING = 'missing';

    public const string REMOVED = 'removed';

    public const string UNREACHABLE = 'unreachable';

    public function __construct(private readonly PageFetcher $fetcher) {}

    /** @return string found | missing | removed | unreachable | skipped */
    public function verify(BacklinkSource $source): string
    {
        if (! in_array($source->status, [BacklinkSource::GIVEN, BacklinkSource::VERIFIED], true)) {
            return 'skipped';
        }
        $brand = $source->brand;
        $own = $brand instanceof Brand ? SiteDomains::ownDomains($brand) : [];
        $url = (string) ($source->link_url ?: $source->url);
        try {
            $html = $this->fetcher->fetch($url)['html'] ?? null;
        } catch (Throwable) {
            $html = null;
        }
        $today = now()->format('d.m.Y');
        if (! is_string($html) || $html === '') {
            $source->forceFill(['checked_at' => now(), 'note' => 'Sayfa açılamadı · '.$today])->save();

            return self::UNREACHABLE;
        }
        if (self::linksTo($html, $own)) {
            $source->forceFill(['status' => BacklinkSource::VERIFIED, 'verified_at' => now(), 'checked_at' => now(), 'note' => null])->save();

            return self::FOUND;
        }
        if ($source->status === BacklinkSource::VERIFIED) {
            $source->forceFill(['status' => BacklinkSource::NONE, 'checked_at' => now(), 'note' => 'Bağlantı kaldırıldı · '.$today])->save();

            return self::REMOVED;
        }
        $source->forceFill(['checked_at' => now(), 'note' => 'Bağlantı bulunamadı · '.$today])->save();

        return self::MISSING;
    }

    /** @param  list<string>  $own  registrable domains */
    public static function linksTo(string $html, array $own): bool
    {
        if ($own === []) {
            return false;
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return false;
        }
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '' || ! preg_match('#^(https?:)?//#i', $href)) {
                continue; // relative links point to the source site itself
            }
            $host = SiteDomains::host(str_starts_with($href, '//') ? 'https:'.$href : $href);
            if ($host !== null && SiteDomains::isOwn($host, $own)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{checked: int, found: int, removed: int} */
    public function verifyAll(): array
    {
        $stats = ['checked' => 0, 'found' => 0, 'removed' => 0];
        BacklinkSource::query()->with('brand.customer')->whereIn('status', [BacklinkSource::GIVEN, BacklinkSource::VERIFIED])
            ->whereHas('brand', fn ($q) => $q->operational())->orderBy('id')
            ->each(function (BacklinkSource $source) use (&$stats): void {
                $result = $this->verify($source);
                $stats['checked']++;
                $stats['found'] += $result === self::FOUND ? 1 : 0;
                $stats['removed'] += $result === self::REMOVED ? 1 : 0;
            }, 100);

        return $stats;
    }
}
