<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A brand's own article cover (yakup 2026-11-09: "Moximu'ya özel görseli oluştursun"). For a brand whose `cover_style`
 * is set, a new article does not take a photo from the site's media library: MoxDOP draws the cover and uploads it with
 * the draft.
 *
 * Moximu's style (yakup 2026-11-12: "mevcut blog gönderi tarzımızı koruyarak kapak görseli üret") is the cover of the
 * guides on moximu.com/rehber: warm light ground; on the left the Moximu Rehber row, the topic chip, the two-tone headline
 * (black, then grey) and "moximu.com/rehber"; on the right a rounded panel with the soft yellow and teal light, holding
 * one interface piece that shows the subject. The piece changes with the topic (a map with profiles, search results, a
 * phone with a form, a checklist, numbered steps, a tracking card), so the blog does not look cut from one mould
 * (motif). Every word on it comes from the article itself: title, headings, focus search, lead. No AI image, no number
 * and no price the article does not carry.
 */
final class BrandCover
{
    public const string MOXIMU_REHBER = 'moximu_rehber';

    public const array STYLES = [self::MOXIMU_REHBER => 'Moximu Rehber kartı'];

    public const int WIDTH = 1600;

    public const int HEIGHT = 900;

    /** Instagram portrait (yakup 2026-11-12: every article also becomes an Instagram draft). */
    public const int PORTRAIT_WIDTH = 1080;

    public const int PORTRAIT_HEIGHT = 1350;

    /** The interface piece of each topic family, in the order they are tried when two fit equally. */
    public const array MOTIFS = [
        'harita' => 'Harita ve profiller',
        'arama' => 'Arama sonuçları',
        'telefon' => 'Telefonda sayfa ve form',
        'liste' => 'Kontrol listesi',
        'adimlar' => 'Numaralı adımlar',
        'olcum' => 'Takip kartı',
    ];

    /** Folded words that point at a motif (title, topic, focus search and headings are read). */
    private const array MOTIF_WORDS = [
        'harita' => ['isletme profili', 'harita', 'yorum', 'yerel', 'google karti', 'konum', 'yol tarifi', 'yakinimdaki', 'yakinda', 'gbp'],
        'olcum' => ['olc', 'rapor', 'butce', 'maliyet', 'performans', 'analiz', 'donusum', 'takip', 'analytics', 'search console', 'kpi', 'hesapla'],
        'telefon' => ['web sitesi', 'site', 'form', 'whatsapp', 'hasta kabul', 'randevu', 'sayfa', 'mobil', 'telefon', 'landing', 'karsilama'],
        'liste' => ['mevzuat', 'yonetmelik', 'yasak', 'ifade', 'kural', 'hata', 'kontrol', 'reklam metni', 'uygun', 'izin'],
        'adimlar' => ['turizm', 'yurt disi', 'adim', 'surec', 'nasil baslan', 'yol haritasi', 'asama', 'kurulum'],
        'arama' => ['seo', 'google ads', 'arama', 'anahtar kelime', 'reklam', 'sira', 'siralama', 'tiklama', 'google'],
    ];

    /** Drawn at twice the size and scaled down, so shapes get smooth edges (GD does not antialias fills). */
    private const int SCALE = 2;

    private const string GROUND = '#F5F4F0';

    private const string INK = '#111827';

    private const string GREY = '#8A8F98';

    private const string MUTED = '#5B6270';

    private const string TEAL = '#0F5A52';

    private const string YELLOW = '#FDCF3C';

    private const string GREEN = '#1F8A5B';

    private \GdImage $img;

    /** Origin and scale of the right-hand panel's interface piece (designed on an 808 × 740 panel). */
    private float $ox = 0;

    private float $oy = 0;

    private float $k = 1;

    public static function styleOf(?Brand $brand): ?string
    {
        $style = (string) ($brand?->cover_style ?? '');

        return isset(self::STYLES[$style]) ? $style : null;
    }

    /**
     * The words of the cover, taken from the written article.
     *
     * @return array{title: string, title_rest: string, lead: string, lead_rest: string, tag: string, question: string, answer: string, query: string, items: list<string>, motif: string}
     */
    public static function words(Suggestion $suggestion, ?string $avoidMotif = null): array
    {
        $action = (array) $suggestion->action;
        $article = (array) ($action['article'] ?? []);
        $full = self::clean((string) ($article['title'] ?? $suggestion->title));
        [$title, $rest] = self::splitTitle($full);
        $html = (string) ($article['html'] ?? '');
        $excerpt = self::clean((string) ($article['excerpt'] ?? '')) ?: self::clean((string) ($article['meta_description'] ?? ''));
        $sentences = self::sentences($excerpt !== '' ? $excerpt : self::firstParagraph($html));
        $question = '';
        $answer = '';
        if (preg_match('~<h2[^>]*>(.*?)</h2>\s*(?:<[^>]+>\s*)*?<p[^>]*>(.*?)</p>~is', $html, $m) === 1) {
            $question = self::clean($m[1]);
            $answer = self::sentences(self::clean($m[2]))[0] ?? '';
        }
        $question = $question !== '' ? $question : ($sentences[1] ?? $title);
        $answer = $answer !== '' ? $answer : ($sentences[0] ?? '');
        $tag = self::clean((string) ($action['service'] ?? data_get($article, 'categories.0', '')));
        $query = self::clean((string) ($action['query'] ?? $article['focus_keyword'] ?? ''));
        $query = $query !== '' ? $query : mb_strtolower(rtrim($full, '?.!'), 'UTF-8');
        $items = self::headings($html);

        return [
            'title' => $title, 'title_rest' => $rest,
            'lead' => (string) ($sentences[0] ?? ''), 'lead_rest' => implode(' ', array_slice($sentences, 1, 1)),
            'tag' => $tag,
            'question' => self::cut(rtrim($question, '.:'), 70).(preg_match('/[?!.]$/u', $question) === 1 ? '' : '?'),
            'answer' => self::cut($answer, 130),
            'query' => self::cut(mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $query), 'UTF-8'), 34),
            'items' => $items,
            'motif' => self::motif(implode(' ', [$full, $tag, $query, ...$items]), $avoidMotif),
        ];
    }

    /** The motif the words point at; `$avoid` (the previous cover's) is passed over when another one fits too. */
    public static function motif(string $text, ?string $avoid = null): string
    {
        $folded = ' '.SeoText::fold($text).' ';
        $scores = [];
        foreach (self::MOTIF_WORDS as $motif => $words) {
            $scores[$motif] = 0;
            foreach ($words as $word) {
                $scores[$motif] += substr_count($folded, $word);
            }
        }
        arsort($scores);
        $ranked = array_keys(array_filter($scores, fn (int $s): bool => $s > 0));
        if ($ranked === []) {
            // Nothing names a family: the next one after the previous cover, so neighbours still differ.
            $keys = array_keys(self::MOTIFS);
            $at = $avoid !== null ? array_search($avoid, $keys, true) : false;

            return $at === false ? 'arama' : $keys[($at + 1) % count($keys)];
        }
        if ($avoid !== null && $ranked[0] === $avoid && isset($ranked[1])) {
            return $ranked[1];
        }

        return $ranked[0];
    }

    /**
     * The article's featured image for a brand with its own cover style: drawn, uploaded to the site's media library
     * (site-tool build › media, undoable on the site) and returned in ArticleImagePicker's shape. Null when the brand has
     * no style or anything fails: the article then takes a picture from the site as before.
     *
     * @return array{media_id: int, url: string, alt: string, why: string, motif?: string}|null
     */
    public function attach(Suggestion $suggestion, DigitalAsset $site): ?array
    {
        $style = self::styleOf($site->brand ?? $suggestion->brand);
        if ($style === null) {
            return null;
        }
        try {
            $words = self::words($suggestion, self::lastMotif((int) $suggestion->brand_id, (int) $suggestion->id));
            $slug = Str::slug((string) data_get($suggestion->action, 'article.slug') ?: $words['title']) ?: 'yazi';
            $ref = 'moxdop-kapak-'.$suggestion->id;
            $alt = mb_substr($words['title'].($words['title_rest'] !== '' ? ' '.$words['title_rest'] : ''), 0, 200);
            $answer = app(WordPressSiteBuilder::class)->tool((int) $site->id, 'build', ['operations' => [[
                'op' => 'media', 'ref' => $ref, 'filename' => 'kapak-'.mb_substr($slug, 0, 70).'.jpg', 'title' => $alt, 'alt' => $alt,
                'data_base64' => base64_encode($this->render($style, $words)),
            ]]]);
            $media = self::find($answer, $ref);
            if ($media === null) {
                throw new RuntimeException('The site did not return the uploaded image.');
            }

            return ['media_id' => (int) $media['id'], 'url' => (string) ($media['url'] ?? ''), 'alt' => $alt, 'why' => 'markanın kapak şablonu ('.self::STYLES[$style].')', 'motif' => $words['motif']];
        } catch (Throwable $e) {
            Log::warning('Brand cover could not be made; the site picture is used.', ['suggestion' => $suggestion->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The motif of the brand's last drawn cover (so two covers in a row differ). */
    public static function lastMotif(int $brandId, int $exceptId = 0): ?string
    {
        $motif = Suggestion::query()->where('brand_id', $brandId)->where('action_type', SiteSuggestionTypes::CONTENT)->whereKeyNot($exceptId)
            ->whereNotNull('action->image->motif')->orderByDesc('resolved_at')->orderByDesc('id')->value('action');
        $value = data_get(is_array($motif) ? $motif : json_decode((string) $motif, true), 'image.motif');

        return is_string($value) && isset(self::MOTIFS[$value]) ? $value : null;
    }

    /** @return array<string, mixed>|null the build result row of this ref */
    private static function find(mixed $answer, string $ref): ?array
    {
        if (! is_array($answer)) {
            return null;
        }
        if (($answer['ref'] ?? null) === $ref && (int) ($answer['id'] ?? 0) > 0) {
            return $answer;
        }
        foreach ($answer as $value) {
            if (($found = self::find($value, $ref)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The cover as a JPEG: the blog cover (1600 × 900) or, with $portrait, the Instagram post (1080 × 1350).
     *
     * @param  array<string, mixed>  $words
     */
    public function render(string $style, array $words, bool $portrait = false): string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            throw new RuntimeException('GD with FreeType is not available.');
        }
        if ($style !== self::MOXIMU_REHBER) {
            throw new RuntimeException('Unknown cover style.');
        }
        $words += ['title' => '', 'title_rest' => '', 'tag' => '', 'query' => '', 'items' => [], 'motif' => 'arama', 'lead' => '', 'answer' => '', 'question' => ''];
        [$width, $height] = $portrait ? [self::PORTRAIT_WIDTH, self::PORTRAIT_HEIGHT] : [self::WIDTH, self::HEIGHT];
        $s = self::SCALE;
        $this->img = imagecreatetruecolor($width * $s, $height * $s);
        imagealphablending($this->img, true);
        imagesavealpha($this->img, false);
        $this->rect(0, 0, $width, $height, self::GROUND);
        $motif = isset(self::MOTIFS[$words['motif']]) ? (string) $words['motif'] : 'arama';

        if ($portrait) {
            $bottom = $this->textBlock($words, 72, 72, 936, 76, 3);
            $py = max(330, $bottom + 56);
            [$px, $pw, $ph] = [72, 936, 1250 - $py];
        } else {
            $this->textBlock($words, 80, 80, 560, 70, 4);
            $this->footer(80, 815);
            [$px, $py, $pw, $ph] = [712, 80, 808, 740];
        }
        $this->panel($px, $py, $pw, $ph, $motif);
        $this->k = min($pw / 808, $ph / 740);
        $this->ox = $px + ($pw - 808 * $this->k) / 2;
        $this->oy = $py + ($ph - 740 * $this->k) / 2;
        match ($motif) {
            'harita' => $this->motifMap($words),
            'telefon' => $this->motifPhone($words),
            'liste' => $this->motifChecklist($words),
            'adimlar' => $this->motifSteps($words),
            'olcum' => $this->motifTracking($words),
            default => $this->motifSearch($words),
        };
        if ($portrait) {
            $this->footer(72, 1300);
        }

        $out = imagecreatetruecolor($width, $height);
        imagecopyresampled($out, $this->img, 0, 0, 0, 0, $width, $height, $width * $s, $height * $s);
        ob_start();
        imagejpeg($out, null, 88);

        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------ the left */

    /** Brand row, topic chip and the two-tone headline from ($x, $y), within $maxWidth. */
    private function textBlock(array $words, int $x, int $y, int $maxWidth, int $size, int $maxLines): int
    {
        $this->mark($x, $y, 44);
        $after = $this->text('Moximu', 'Inter-Medium', 29, $x + 58, $y + 33, self::INK);
        $this->text('Rehber', 'Inter-Regular', 29, $after + 14, $y + 33, self::GREY);
        $top = $y + 72;
        if ($words['tag'] !== '') {
            $tag = self::cut((string) $words['tag'], 34);
            $w = $this->width($tag, 'Inter-Medium', 20) + 40;
            $this->rounded($x, $top, $x + $w, $top + 44, 22, '#DCEDE7');
            $this->text($tag, 'Inter-Medium', 20, $x + 20, $top + 29, self::TEAL);
            $top += 44;
        }
        $top += 52;
        $headSize = $size;
        do {
            $head = $this->wrap((string) $words['title'], 'Inter-Medium', $headSize, $maxWidth);
            $rest = $words['title_rest'] !== '' ? $this->wrap((string) $words['title_rest'], 'Inter-Regular', $headSize, $maxWidth) : [];
            $headSize -= 4;
        } while (count($head) + count($rest) > $maxLines && $headSize > 40);
        $headSize += 4;
        $line = (int) round($headSize * 1.1);
        foreach ($head as $text) {
            $this->text($text, 'Inter-Medium', $headSize, $x - 2, $top + $headSize, self::INK);
            $top += $line;
        }
        foreach ($rest as $text) {
            $this->text($text, 'Inter-Regular', $headSize, $x - 2, $top + $headSize, self::GREY);
            $top += $line;
        }

        return $top;
    }

    private function footer(int $x, int $baseline): void
    {
        $after = $this->text('moximu.com', 'Inter-Medium', 24, $x, $baseline, self::INK);
        $this->text('/rehber', 'Inter-Regular', 24, $after, $baseline, self::GREY);
    }

    /* ----------------------------------------------------------- the panel */

    /**
     * The rounded panel with the soft light: a cool mint ground, a yellow glow in one corner and a teal one in the
     * opposite corner (mirrored by motif so neighbouring covers differ a little), drawn small and scaled up smooth.
     */
    private function panel(int $x, int $y, int $w, int $h, string $motif): void
    {
        $s = self::SCALE;
        $mirror = in_array($motif, ['telefon', 'adimlar', 'olcum'], true);
        $gw = 96;
        $gh = (int) max(8, round($gw * $h / $w));
        $small = imagecreatetruecolor($gw, $gh);
        $base = [233, 240, 236];
        $glows = [
            ['at' => [$mirror ? 0.08 : 0.92, 0.1], 'rgb' => [253, 212, 72], 'r' => 0.75],
            ['at' => [$mirror ? 0.95 : 0.04, 0.98], 'rgb' => [160, 214, 196], 'r' => 0.6],
            ['at' => [0.5, 0.5], 'rgb' => [250, 243, 220], 'r' => 0.45],
        ];
        for ($j = 0; $j < $gh; $j++) {
            for ($i = 0; $i < $gw; $i++) {
                [$u, $v] = [$i / max(1, $gw - 1), $j / max(1, $gh - 1)];
                $rgb = $base;
                foreach ($glows as $glow) {
                    $d = hypot(($u - $glow['at'][0]) * $w / $h, $v - $glow['at'][1]) / $glow['r'];
                    $a = max(0.0, 1 - $d);
                    $a = $a * $a * (3 - 2 * $a);
                    foreach ([0, 1, 2] as $c) {
                        $rgb[$c] = $rgb[$c] * (1 - $a) + $glow['rgb'][$c] * $a;
                    }
                }
                imagesetpixel($small, $i, $j, imagecolorallocate($small, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]));
            }
        }
        imagecopyresampled($this->img, $small, $x * $s, $y * $s, 0, 0, $w * $s, $h * $s, $gw, $gh);
        // Rounded corners: the ground colour back outside the radius, softened over one pixel.
        $r = 40 * $s;
        [$gr, $gg, $gb] = sscanf(ltrim(self::GROUND, '#'), '%02x%02x%02x');
        foreach ([[$x * $s, $y * $s, 1, 1], [($x + $w) * $s - 1, $y * $s, -1, 1], [$x * $s, ($y + $h) * $s - 1, 1, -1], [($x + $w) * $s - 1, ($y + $h) * $s - 1, -1, -1]] as [$cx, $cy, $dx, $dy]) {
            for ($j = 0; $j < $r; $j++) {
                for ($i = 0; $i < $r; $i++) {
                    $d = hypot($r - $i - 0.5, $r - $j - 0.5) - $r;
                    if ($d <= -1) {
                        continue;
                    }
                    $px = $cx + $dx * $i;
                    $py = $cy + $dy * $j;
                    $a = min(1.0, max(0.0, $d + 1));
                    $old = imagecolorat($this->img, $px, $py);
                    $col = [($old >> 16) & 255, ($old >> 8) & 255, $old & 255];
                    imagesetpixel($this->img, $px, $py, imagecolorallocate($this->img,
                        (int) ($col[0] * (1 - $a) + $gr * $a), (int) ($col[1] * (1 - $a) + $gg * $a), (int) ($col[2] * (1 - $a) + $gb * $a)));
                }
            }
        }
        unset($small);
    }

    /* -------------------------------------------------------------- motifs */

    /** Search bar, a map with pins and three profiles (yours first), plus a checklist from the headings. */
    private function motifMap(array $words): void
    {
        $this->card(40, 120, 590, 500);
        $this->searchBar(64, 144, 542, (string) $words['query']);
        // The map: a light grid with two grey pins and the red one.
        $this->m('rounded', 64, 216, 606, 344, 18, '#EEF0EC');
        for ($gx = 128; $gx < 606; $gx += 66) {
            $this->m('rect', $gx, 216, $gx + 3, 344, '#FFFFFF');
        }
        foreach ([258, 300] as $gy) {
            $this->m('rect', 64, $gy, 606, $gy + 3, '#FFFFFF');
        }
        $this->pin(160, 266, '#C9CCC8');
        $this->pin(300, 240, '#C9CCC8');
        $this->pin(410, 282, '#D94B3D');
        $rows = [['Sizin kliniğiniz', true], ['Klinik A', false], ['Klinik B', false]];
        foreach ($rows as $i => [$name, $own]) {
            $top = 364 + $i * 78;
            if ($own) {
                $this->m('rounded', 64, $top, 606, $top + 66, 14, '#FDF6DC');
                $this->m('disc', 96, $top + 33, 15, self::TEAL);
                $this->m('check', 96, $top + 33, 8, '#FFFFFF');
            } else {
                $this->m('disc', 96, $top + 33, 15, '#EEF0EC');
                $this->m('text', mb_substr($name, -1), 'Inter-Medium', 15, 91, $top + 39, self::GREY);
            }
            $this->m('text', $name, 'Inter-Medium', 21, 126, $top + 30, self::INK);
            $this->stars(126, $top + 50, $own ? 5 : 4);
        }
        $items = array_slice($words['items'], 0, 4);
        if ($items !== []) {
            $this->checkCard(440, 460, 330, $items);
        }
    }

    /** A search bar and three results, the first one the reader's own page, plus a short rule card. */
    private function motifSearch(array $words): void
    {
        $this->card(40, 110, 600, 470);
        $this->searchBar(64, 134, 552, (string) $words['query']);
        $title = self::cut(trim($words['title'].' '.$words['title_rest']), 46);
        $paid = str_contains(SeoText::fold(implode(' ', [$words['title'], $words['title_rest'], $words['tag'], $words['query']])), 'ads')
            || str_contains(SeoText::fold((string) $words['tag']), 'reklam');
        foreach ([0, 1, 2] as $i) {
            $top = 214 + $i * 128;
            if ($i === 0) {
                $this->m('rounded', 64, $top, 616, $top + 110, 16, '#FDF6DC');
                $this->mark2(84, $top + 20, 30);
                $this->m('text', $paid ? 'Sponsorlu · Sizin sayfanız' : 'Sizin sayfanız', 'Inter-Medium', 15, 126, $top + 40, self::MUTED);
                $this->m('text', $this->fit($title, 'Inter-Medium', 21, 500), 'Inter-Medium', 21, 84, $top + 80, self::TEAL);
            } else {
                $this->m('disc', 99, $top + 35, 15, '#EEF0EC');
                $this->m('rounded', 126, $top + 22, 300, $top + 34, 6, '#E3E5E1');
                $this->m('rounded', 84, $top + 64, 560 - $i * 50, $top + 80, 8, '#D9DCD7');
                $this->m('rounded', 84, $top + 92, 470 - $i * 40, $top + 102, 5, '#ECEEEA');
            }
        }
        $note = $words['items'][0] ?? '';
        $line = (string) ($words['answer'] ?: $words['lead']);
        if ($note !== '' || $line !== '') {
            $this->noteCard(470, 450, 310, 'Önce', $note !== '' ? $note : $line, $note !== '' ? $line : '');
        }
    }

    /** A phone with the page and its short form, a WhatsApp button and a note on what the form needs. */
    private function motifPhone(array $words): void
    {
        // The phone.
        $this->m('shadow', 274, 96, 554, 690, 46);
        $this->m('rounded', 274, 96, 554, 690, 46, '#141A22');
        $this->m('rounded', 286, 108, 542, 678, 36, '#FFFFFF');
        $this->m('rounded', 304, 150, 524, 156, 3, self::TEAL);
        $this->m('text', 'Rehber', 'Inter-Regular', 13, 306, 186, self::GREY);
        $head = array_slice($this->wrapK(trim($words['title'].' '.$words['title_rest']), 'Inter-Medium', 19, 214), 0, 3);
        $y = 214;
        foreach ($head as $line) {
            $this->m('text', $line, 'Inter-Medium', 19, 306, $y, self::INK);
            $y += 25;
        }
        $y += 10;
        foreach (['Adınız', 'Telefon'] as $label) {
            $this->m('rounded', 304, $y, 524, $y + 44, 10, '#FFFFFF');
            $this->m('ring', 304, $y, 524, $y + 44, 10, '#DCDFDA');
            $this->m('text', $label, 'Inter-Regular', 15, 318, $y + 28, self::GREY);
            $y += 56;
        }
        $this->m('rounded', 304, $y + 4, 524, $y + 52, 10, self::TEAL);
        $label = 'Bilgi alın';
        $this->m('text', $label, 'Inter-Medium', 17, (int) (414 - $this->width($label, 'Inter-Medium', 17) / 2), $y + 34, '#FFFFFF');
        $this->m('disc', 498, 630, 24, '#25D366');
        $this->m('wa', 498, 630);
        $items = array_slice($words['items'], 0, 3);
        if ($items !== []) {
            $this->noteCard(40, 380, 250, 'Sayfada olmalı', implode(', ', $items), '');
        }
        $tag = (string) ($words['query'] ?: $words['tag']);
        if ($tag !== '') {
            $this->card(500, 150, 290, 92);
            $this->mark2(518, 170, 40);
            $this->m('text', $this->fit('Yeni talep', 'Inter-Medium', 16, 200), 'Inter-Medium', 16, 572, 190, self::INK);
            $this->m('text', $this->fit($tag, 'Inter-Regular', 14, 200), 'Inter-Regular', 14, 572, 214, self::GREY);
        }
    }

    /** The headings as a stack of checked cards. */
    private function motifChecklist(array $words): void
    {
        $items = array_slice($words['items'] !== [] ? $words['items'] : array_filter([(string) $words['question'], (string) $words['answer']]), 0, 4);
        $count = max(1, count($items));
        $gap = 18;
        $cardH = 92;
        $top = (int) (370 - ($count * $cardH + ($count - 1) * $gap) / 2);
        foreach ($items as $i => $item) {
            $y = $top + $i * ($cardH + $gap);
            $this->card(60 + ($i % 2) * 24, $y, 680 - ($i % 2) * 24, $cardH);
            $this->m('disc', 108 + ($i % 2) * 24, $y + 46, 17, self::GREEN);
            $this->m('check', 108 + ($i % 2) * 24, $y + 46, 9, '#FFFFFF');
            $this->m('text', $this->fit($item, 'Inter-Medium', 23, 540), 'Inter-Medium', 23, 142 + ($i % 2) * 24, $y + 55, self::INK);
        }
    }

    /** Three numbered step cards from the headings, lined down the panel. */
    private function motifSteps(array $words): void
    {
        $items = array_slice($words['items'] !== [] ? $words['items'] : [(string) $words['question']], 0, 3);
        $places = [[60, 110], [250, 300], [100, 490]];
        foreach ($items as $i => $item) {
            [$x, $y] = $places[$i];
            $this->card($x, $y, 460, 132);
            $this->m('disc', $x + 2, $y + 2, 20, self::INK);
            $this->m('text', (string) ($i + 1), 'Inter-Medium', 17, $x - 3, $y + 9, '#FFFFFF');
            $this->m('text', 'Adım '.($i + 1), 'Inter-Regular', 15, $x + 32, $y + 46, self::GREY);
            foreach (array_slice($this->wrapK($item, 'Inter-Medium', 22, 400), 0, 2) as $l => $line) {
                $this->m('text', $line, 'Inter-Medium', 22, $x + 32, $y + 84 + $l * 30, self::INK);
            }
        }
    }

    /** A tracking card: what to watch (the headings) with bars that only show direction, no figures. */
    private function motifTracking(array $words): void
    {
        $items = array_slice($words['items'] !== [] ? $words['items'] : [(string) $words['question']], 0, 4);
        $this->card(90, 110, 560, 120 + count($items) * 100);
        $this->m('text', 'Takip edilecekler', 'Inter-Medium', 16, 122, 160, self::MUTED);
        $lengths = [0.86, 0.64, 0.74, 0.5];
        foreach ($items as $i => $item) {
            $y = 196 + $i * 100;
            $this->m('text', $this->fit($item, 'Inter-Medium', 21, 480), 'Inter-Medium', 21, 122, $y + 22, self::INK);
            $this->m('rounded', 122, $y + 42, 618, $y + 56, 7, '#EEF0EC');
            $this->m('rounded', 122, $y + 42, (int) (122 + 496 * $lengths[$i]), $y + 56, 7, $i === 0 ? self::TEAL : '#9FCDBE');
            if ($i < count($items) - 1) {
                $this->m('rect', 122, $y + 78, 618, $y + 79, '#EEF0EC');
            }
        }
        $line = (string) ($words['answer'] ?: $words['lead']);
        if ($line !== '') {
            $this->noteCard(500, 40, 280, 'Kural', $line, '');
        }
    }

    /* -------------------------------------------------- motif building blocks */

    private function searchBar(int $x, int $y, int $w, string $query): void
    {
        $this->m('rounded', $x, $y, $x + $w, $y + 56, 28, '#FFFFFF');
        $this->m('ring', $x, $y, $x + $w, $y + 56, 28, '#DCDFDA');
        $this->m('circle', $x + 34, $y + 26, 9, '#4285F4');
        $this->m('line', $x + 40, $y + 32, $x + 46, $y + 38, '#4285F4');
        $this->m('text', $this->fit($query !== '' ? $query : 'klinik', 'Inter-Regular', 21, $w - 90), 'Inter-Regular', 21, $x + 62, $y + 35, self::INK);
    }

    /** @param list<string> $items */
    private function checkCard(int $x, int $y, int $w, array $items): void
    {
        $this->card($x, $y, $w, 32 + count($items) * 46);
        foreach ($items as $i => $item) {
            $cy = $y + 39 + $i * 46;
            $this->m('disc', $x + 34, $cy, 14, self::GREEN);
            $this->m('check', $x + 34, $cy, 7, '#FFFFFF');
            $this->m('text', $this->fit($item, 'Inter-Regular', 19, $w - 80), 'Inter-Regular', 19, $x + 60, $cy + 7, self::INK);
        }
    }

    private function noteCard(int $x, int $y, int $w, string $label, string $body, string $more): void
    {
        $lines = array_slice($this->wrapK($body, 'Inter-Regular', 17, $w - 44), 0, 3);
        $extra = $more !== '' ? array_slice($this->wrapK($more, 'Inter-Regular', 15, $w - 44), 0, 2) : [];
        $h = 58 + count($lines) * 24 + ($extra !== [] ? 10 + count($extra) * 21 : 0);
        $this->card($x, $y, $w, $h);
        $this->m('text', $label, 'Inter-Medium', 14, $x + 22, $y + 32, self::TEAL);
        $yy = $y + 58;
        foreach ($lines as $line) {
            $this->m('text', $line, 'Inter-Regular', 17, $x + 22, $yy, self::INK);
            $yy += 24;
        }
        $yy += 6;
        foreach ($extra as $line) {
            $this->m('text', $line, 'Inter-Regular', 15, $x + 22, $yy, self::MUTED);
            $yy += 21;
        }
    }

    private function card(int $x, int $y, int $w, int $h): void
    {
        $this->m('shadow', $x, $y, $x + $w, $y + $h, 24);
        $this->m('rounded', $x, $y, $x + $w, $y + $h, 24, '#FFFFFF');
    }

    private function pin(int $x, int $y, string $color): void
    {
        $this->m('disc', $x, $y, 14, $color);
        $this->m('triangle', $x, $y, $color);
        $this->m('disc', $x, $y, 5, '#FFFFFF');
    }

    private function stars(int $x, int $baseline, int $count): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->m('star', $x + 9 + $i * 20, $baseline - 6, 8, $i < $count ? '#F4B400' : '#DADCD8');
        }
    }

    private function mark2(int $x, int $y, int $size): void
    {
        $this->mark((int) round($this->ox + $x * $this->k), (int) round($this->oy + $y * $this->k), (int) round($size * $this->k));
    }

    /** One drawing call in the panel's coordinate system (scaled and moved onto the canvas). */
    private function m(string $what, mixed ...$a): void
    {
        $X = fn (float $v): int => (int) round($this->ox + $v * $this->k);
        $Y = fn (float $v): int => (int) round($this->oy + $v * $this->k);
        $L = fn (float $v): int => max(1, (int) round($v * $this->k));
        match ($what) {
            'rect' => $this->rect($X($a[0]), $Y($a[1]), $X($a[2]), $Y($a[3]), $a[4]),
            'rounded' => $this->rounded($X($a[0]), $Y($a[1]), $X($a[2]), $Y($a[3]), $L($a[4]), $a[5]),
            'ring' => $this->ring($X($a[0]), $Y($a[1]), $X($a[2]), $Y($a[3]), $L($a[4]), $a[5]),
            'shadow' => $this->shadow($X($a[0]), $Y($a[1]), $X($a[2]), $Y($a[3]), $L($a[4])),
            'disc' => $this->disc($X($a[0]), $Y($a[1]), $L($a[2]), $a[3]),
            'circle' => $this->circle($X($a[0]), $Y($a[1]), $L($a[2]), $a[3]),
            'line' => $this->line($X($a[0]), $Y($a[1]), $X($a[2]), $Y($a[3]), $a[4]),
            'check' => $this->check($X($a[0]), $Y($a[1]), $L($a[2]), $a[3]),
            'triangle' => $this->triangle($X($a[0]), $Y($a[1]), $L(14), $a[2]),
            'star' => $this->star($X($a[0]), $Y($a[1]), $L($a[2]), $a[3]),
            'wa' => $this->whatsapp($X($a[0]), $Y($a[1]), $L(11)),
            'text' => $this->text((string) $a[0], (string) $a[1], max(8, (int) round($a[2] * $this->k)), $X($a[3]), $Y($a[4]), (string) $a[5]),
        };
    }

    /** Wraps in panel units. @return list<string> */
    private function wrapK(string $text, string $font, int $size, int $max): array
    {
        return $this->wrap($text, $font, max(8, (int) round($size * $this->k)), (int) round($max * $this->k));
    }

    /** The text cut at a word so it fits $max panel units at $size. */
    private function fit(string $text, string $font, int $size, int $max): string
    {
        $px = max(8, (int) round($size * $this->k));
        $limit = (int) round($max * $this->k);
        if ($this->width($text, $font, $px) <= $limit) {
            return $text;
        }
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        while (count($words) > 1) {
            array_pop($words);
            $try = rtrim(implode(' ', $words), ' ,;:').'…';
            if ($this->width($try, $font, $px) <= $limit) {
                return $try;
            }
        }

        return mb_substr($text, 0, 18).'…';
    }

    /* ---------------------------------------------------------------- text */

    /** @return array{0: string, 1: string} "SEO nasıl ölçülür? Dört rakamla." → black and grey part */
    private static function splitTitle(string $title): array
    {
        if (preg_match('/^(.{12,}?[?:])\s+(\S.{3,})$/u', $title, $m) === 1) {
            return [rtrim($m[1], ':'), $m[2]];
        }
        // Like the guides on the site ("Reklam bütçesi / hedeften geriye hesaplanır."): the first part black, the rest grey,
        // split at the word nearest to two fifths of the title.
        $words = preg_split('/\s+/u', trim($title)) ?: [];
        if (count($words) < 4) {
            return [$title, ''];
        }
        $best = 2;
        $bestGap = PHP_INT_MAX;
        for ($i = 2; $i <= count($words) - 2; $i++) {
            $gap = abs(mb_strlen(implode(' ', array_slice($words, 0, $i))) - (int) round(mb_strlen($title) * 0.4));
            if ($gap < $bestGap) {
                [$best, $bestGap] = [$i, $gap];
            }
        }

        return [implode(' ', array_slice($words, 0, $best)), implode(' ', array_slice($words, $best))];
    }

    /**
     * The article's headings, short enough for a card line: "Sık sorulan sorular" and closing headings are left out.
     *
     * @return list<string>
     */
    private static function headings(string $html): array
    {
        preg_match_all('~<h2[^>]*>(.*?)</h2>~is', $html, $m);
        $out = [];
        foreach ($m[1] ?? [] as $heading) {
            $text = rtrim(self::clean($heading), '?.:');
            $folded = SeoText::fold($text);
            if ($text === '' || preg_match('/^(sik sorulan|sss|sonuc|ozet|kaynak|bize ulas|iletisim|moximu ile)/', $folded) === 1) {
                continue;
            }
            $out[] = self::cut($text, 34);
        }

        return array_values(array_unique($out));
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // Typographic quotes, as on the approved covers.
        return (string) preg_replace('/"([^"]*)"/u', '“$1”', $text);
    }

    private static function firstParagraph(string $html): string
    {
        return preg_match('~<p[^>]*>(.*?)</p>~is', $html, $m) === 1 ? self::clean($m[1]) : '';
    }

    /** @return list<string> */
    private static function sentences(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+(?=[A-ZÇĞİÖŞÜ"“])/u', $text) ?: [])));
    }

    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space !== false ? $space : $max), ' ,;:').'…';
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $max): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $this->width($try, $font, $size) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }

        return $line === '' ? $lines : [...$lines, $line];
    }

    private function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size * self::SCALE * 0.75, 0, self::font($font), $text);

        return (int) ceil(($box[2] - $box[0]) / self::SCALE);
    }

    /** Writes at the baseline; returns the x after the text. */
    private function text(string $text, string $font, int $size, int $x, int $baseline, string $color): int
    {
        $s = self::SCALE;
        imagettftext($this->img, $size * $s * 0.75, 0, $x * $s, $baseline * $s, $this->color($color), self::font($font), $text);

        return $x + $this->width($text, $font, $size);
    }

    private static function font(string $name): string
    {
        return resource_path('covers/moximu/'.$name.'.ttf');
    }

    /* -------------------------------------------------------------- shapes */

    private function color(string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return imagecolorallocatealpha($this->img, (int) $r, (int) $g, (int) $b, $alpha);
    }

    private function rect(int $x1, int $y1, int $x2, int $y2, string $color, int $alpha = 0): void
    {
        $s = self::SCALE;
        imagefilledrectangle($this->img, $x1 * $s, $y1 * $s, $x2 * $s - 1, $y2 * $s - 1, $this->color($color, $alpha));
    }

    private function rounded(int $x1, int $y1, int $x2, int $y2, int $r, string $color, int $alpha = 0): void
    {
        $s = self::SCALE;
        [$x1, $y1, $x2, $y2, $r] = [$x1 * $s, $y1 * $s, $x2 * $s, $y2 * $s, min($r * $s, (int) (($y2 - $y1) * $s / 2), (int) (($x2 - $x1) * $s / 2))];
        $c = $this->color($color, $alpha);
        imagefilledrectangle($this->img, $x1 + $r, $y1, $x2 - $r, $y2, $c);
        imagefilledrectangle($this->img, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r, $c);
        imagefilledrectangle($this->img, $x2 - $r + 1, $y1 + $r, $x2, $y2 - $r, $c);
        foreach ([[$x1 + $r, $y1 + $r, 180], [$x2 - $r, $y1 + $r, 270], [$x1 + $r, $y2 - $r, 90], [$x2 - $r, $y2 - $r, 0]] as [$cx, $cy, $start]) {
            imagefilledarc($this->img, $cx, $cy, $r * 2, $r * 2, $start, $start + 90, $c, IMG_ARC_PIE);
        }
    }

    /** A one-pixel (at cover size) outline of a rounded box. */
    private function ring(int $x1, int $y1, int $x2, int $y2, int $r, string $color): void
    {
        $this->rounded($x1, $y1, $x2, $y2, $r, $color);
        $s = self::SCALE;
        // Inset by one cover pixel, filled white again.
        $inner = function () use ($x1, $y1, $x2, $y2, $r, $s): void {
            $c = $this->color('#FFFFFF');
            [$a1, $b1, $a2, $b2, $rr] = [$x1 * $s + $s, $y1 * $s + $s, $x2 * $s - $s, $y2 * $s - $s, max(1, $r * $s - $s)];
            $rr = min($rr, (int) (($b2 - $b1) / 2));
            imagefilledrectangle($this->img, $a1 + $rr, $b1, $a2 - $rr, $b2, $c);
            imagefilledrectangle($this->img, $a1, $b1 + $rr, $a1 + $rr - 1, $b2 - $rr, $c);
            imagefilledrectangle($this->img, $a2 - $rr + 1, $b1 + $rr, $a2, $b2 - $rr, $c);
            foreach ([[$a1 + $rr, $b1 + $rr, 180], [$a2 - $rr, $b1 + $rr, 270], [$a1 + $rr, $b2 - $rr, 90], [$a2 - $rr, $b2 - $rr, 0]] as [$cx, $cy, $start]) {
                imagefilledarc($this->img, $cx, $cy, $rr * 2, $rr * 2, $start, $start + 90, $c, IMG_ARC_PIE);
            }
        };
        $inner();
    }

    /** A soft drop shadow under a card: a few faint, growing layers. */
    private function shadow(int $x1, int $y1, int $x2, int $y2, int $r): void
    {
        foreach ([[32, 126], [22, 125], [12, 124], [4, 123]] as [$grow, $alpha]) {
            $this->rounded((int) ($x1 - $grow / 2), (int) ($y1 + 8 - $grow / 4), (int) ($x2 + $grow / 2), (int) ($y2 + 8 + $grow / 2), (int) ($r + $grow / 2), '#0B1220', $alpha);
        }
    }

    private function disc(int $cx, int $cy, int $r, string $color): void
    {
        $s = self::SCALE;
        imagefilledellipse($this->img, $cx * $s, $cy * $s, $r * 2 * $s, $r * 2 * $s, $this->color($color));
    }

    private function circle(int $cx, int $cy, int $r, string $color): void
    {
        $s = self::SCALE;
        imagesetthickness($this->img, 3 * $s);
        imageellipse($this->img, $cx * $s, $cy * $s, $r * 2 * $s, $r * 2 * $s, $this->color($color));
        imagesetthickness($this->img, 1);
    }

    private function line(int $x1, int $y1, int $x2, int $y2, string $color): void
    {
        $s = self::SCALE;
        imagesetthickness($this->img, 3 * $s);
        imageline($this->img, $x1 * $s, $y1 * $s, $x2 * $s, $y2 * $s, $this->color($color));
        imagesetthickness($this->img, 1);
    }

    /** A tick inside a disc of radius ~2r. */
    private function check(int $cx, int $cy, int $r, string $color): void
    {
        $s = self::SCALE;
        imagesetthickness($this->img, max(2, (int) round($r * 0.38)) * $s);
        $c = $this->color($color);
        imageline($this->img, (int) (($cx - $r * 0.75) * $s), (int) (($cy + $r * 0.05) * $s), (int) (($cx - $r * 0.15) * $s), (int) (($cy + $r * 0.6) * $s), $c);
        imageline($this->img, (int) (($cx - $r * 0.15) * $s), (int) (($cy + $r * 0.6) * $s), (int) (($cx + $r * 0.85) * $s), (int) (($cy - $r * 0.6) * $s), $c);
        imagesetthickness($this->img, 1);
    }

    /** The point of a map pin under a disc at ($cx, $cy). */
    private function triangle(int $cx, int $cy, int $r, string $color): void
    {
        $s = self::SCALE;
        imagefilledpolygon($this->img, [($cx - $r * 0.82) * $s, ($cy + $r * 0.45) * $s, ($cx + $r * 0.82) * $s, ($cy + $r * 0.45) * $s, $cx * $s, ($cy + $r * 1.55) * $s], $this->color($color));
    }

    private function star(int $cx, int $cy, int $r, string $color): void
    {
        $s = self::SCALE;
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $angle = -M_PI / 2 + $i * M_PI / 5;
            $radius = $i % 2 === 0 ? $r : $r * 0.45;
            $points[] = (int) round(($cx + cos($angle) * $radius) * $s);
            $points[] = (int) round(($cy + sin($angle) * $radius) * $s);
        }
        imagefilledpolygon($this->img, $points, $this->color($color));
    }

    /** A plain speech bubble with a handset line, on the green disc. */
    private function whatsapp(int $cx, int $cy, int $r): void
    {
        $this->circle($cx, $cy, $r, '#FFFFFF');
        $this->disc((int) ($cx - $r * 0.25), (int) ($cy + $r * 0.05), max(2, (int) ($r * 0.22)), '#FFFFFF');
        $this->disc((int) ($cx + $r * 0.25), (int) ($cy - $r * 0.05), max(2, (int) ($r * 0.22)), '#FFFFFF');
    }

    private function mark(int $x, int $y, int $size): void
    {
        $mark = imagecreatefrompng(resource_path('covers/moximu/mark.png'));
        if ($mark === false) {
            return;
        }
        $s = self::SCALE;
        imagecopyresampled($this->img, $mark, $x * $s, $y * $s, 0, 0, $size * $s, $size * $s, imagesx($mark), imagesy($mark));
    }
}
