<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MoxDop\Website\Discovery\DiscoveryConfig;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use Throwable;

/**
 * moxdop:website:probe {url} — why the server cannot read a site: DNS, TCP 443, one read as the MoxDOP reader and
 * one as an ordinary browser (longer wait). Every request timing out while the site opens elsewhere means the
 * hosting firewall blocks this server's IP; only the MoxDOP read failing means the user agent is blocked.
 */
final class WebsiteProbeCommand extends Command
{
    private const BROWSER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    protected $signature = 'moxdop:website:probe {url : Sitenin adresi (ör. https://ornek.com/)}';

    protected $description = 'Sunucudan bir web sitesine erişimi dener (DNS, bağlantı, MoxDOP okuyucusu, tarayıcı) ve engeli gösterir.';

    public function handle(PublicHttpFetcher $fetcher): int
    {
        $url = trim((string) $this->argument('url'));
        $url = str_contains($url, '://') ? $url : 'https://'.$url;
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            $this->error('Geçersiz adres.');

            return self::FAILURE;
        }

        $this->line('Sunucunun dış IP adresi: '.($this->publicIp() ?? 'bulunamadı'));

        $addresses = gethostbynamel($host) ?: [];
        $this->line('DNS: '.($addresses === [] ? 'çözülemedi' : implode(', ', $addresses)));

        $started = microtime(true);
        $socket = @fsockopen('tcp://'.$host, 443, $errno, $errstr, 8);
        $tcpOk = is_resource($socket);
        if ($tcpOk) {
            fclose($socket);
        }
        $this->line(sprintf('Bağlantı (443): %s (%.1f sn)', $tcpOk ? 'açık' : 'kurulamadı — '.$errstr, microtime(true) - $started));

        $started = microtime(true);
        $fetch = $fetcher->fetch($url);
        $readerOk = ($fetch['error'] ?? null) === null && (int) ($fetch['status_code'] ?? 0) > 0 && (int) $fetch['status_code'] < 400;
        $this->line(sprintf('MoxDOP okuyucusu (%d sn sınır): %s (%.1f sn)', DiscoveryConfig::TIMEOUT_SECONDS,
            $readerOk ? 'HTTP '.$fetch['status_code'] : (($fetch['error'] ?? null) ?: 'HTTP '.($fetch['status_code'] ?? 0)), microtime(true) - $started));

        $started = microtime(true);
        try {
            $response = Http::withHeaders(['User-Agent' => self::BROWSER_AGENT])->connectTimeout(10)->timeout(45)->get($url);
            $browser = 'HTTP '.$response->status();
            $browserOk = $response->status() < 400;
        } catch (Throwable $error) {
            $browser = $error->getMessage();
            $browserOk = false;
        }
        $this->line(sprintf('Tarayıcı gibi (45 sn sınır): %s (%.1f sn)', $browser, microtime(true) - $started));

        $this->newLine();
        $this->info(match (true) {
            $readerOk => 'Site okunabiliyor. Çekimi yeniden başlatabilirsiniz.',
            $browserOk => 'Site tarayıcıya açılıyor ama MoxDOP okuyucusuna açılmıyor: güvenlik duvarı "MoxDOP-SiteReader" kullanıcı ajanını engelliyor olabilir. Hostingden bu ajanı / sunucu IP’sini beyaz listeye almalarını isteyin.',
            ! $tcpOk || $addresses === [] => 'Sunucu siteye bağlanamıyor: hosting güvenlik duvarı (Imunify360 / CSF / LiteSpeed) bu sunucunun IP’sini engellemiş olabilir. Yukarıdaki IP’yi hostinge verip engeli kaldırmalarını ve beyaz listeye almalarını isteyin.',
            default => 'Bağlantı kuruluyor ama sayfa gelmiyor: hosting güvenlik duvarı bu IP’yi yavaşlatıyor/engelliyor olabilir ya da site aşırı yavaş. Yukarıdaki IP’yi hostinge verip beyaz listeye almalarını isteyin.',
        });

        return self::SUCCESS;
    }

    private function publicIp(): ?string
    {
        try {
            $ip = trim(Http::connectTimeout(5)->timeout(8)->get('https://api.ipify.org')->body());

            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
        } catch (Throwable) {
            return null;
        }
    }
}
