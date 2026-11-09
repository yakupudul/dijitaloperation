<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A brand's own article cover (yakup 2026-11-09: "Moximu'ya özel görseli oluştursun"). For a brand whose
 * `cover_style` is set, a new article does not take a photo from the site's media library: MoxDOP draws the cover in
 * the brand's approved style and uploads it with the draft. Moximu's style is the "Rehber" card yakup approved for
 * moximu.com and its social accounts (2026-11-01 / 2026-11-06, tools/social-visuals): white text half with the two-tone
 * headline and the Moximu Rehber row, a light panel with the reader's question, the yellow disc with the mark, the
 * Moximu answer and the teal "Rehberi okuyun" pill. Rules only: no AI image, only words of the article itself.
 */
final class BrandCover
{
    public const string MOXIMU_REHBER = 'moximu_rehber';

    public const array STYLES = [self::MOXIMU_REHBER => 'Moximu Rehber kartı'];

    public const int WIDTH = 1600;

    public const int HEIGHT = 900;

    /** Drawn at twice the size and scaled down, so shapes get smooth edges (GD does not antialias fills). */
    private const int SCALE = 2;

    private \GdImage $img;

    public static function styleOf(?Brand $brand): ?string
    {
        $style = (string) ($brand?->cover_style ?? '');

        return isset(self::STYLES[$style]) ? $style : null;
    }

    /**
     * The words of the cover, taken from the written article.
     *
     * @return array{title: string, title_rest: string, lead: string, lead_rest: string, tag: string, question: string, answer: string}
     */
    public static function words(Suggestion $suggestion): array
    {
        $action = (array) $suggestion->action;
        $article = (array) ($action['article'] ?? []);
        $title = self::clean((string) ($article['title'] ?? $suggestion->title));
        [$title, $rest] = self::splitTitle($title);
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

        return [
            'title' => $title, 'title_rest' => $rest,
            'lead' => (string) ($sentences[0] ?? ''), 'lead_rest' => implode(' ', array_slice($sentences, 1, 1)),
            'tag' => self::clean((string) ($action['service'] ?? data_get($article, 'categories.0', ''))),
            'question' => self::cut(rtrim($question, '.:'), 70).(preg_match('/[?!.]$/u', $question) === 1 ? '' : '?'),
            'answer' => self::cut($answer, 130),
        ];
    }

    /**
     * The article's featured image for a brand with its own cover style: drawn, uploaded to the site's media library
     * (site-tool build › media, undoable on the site) and returned in ArticleImagePicker's shape. Null when the brand has
     * no style or anything fails: the article then takes a picture from the site as before.
     *
     * @return array{media_id: int, url: string, alt: string, why: string}|null
     */
    public function attach(Suggestion $suggestion, DigitalAsset $site): ?array
    {
        $style = self::styleOf($site->brand ?? $suggestion->brand);
        if ($style === null) {
            return null;
        }
        try {
            $words = self::words($suggestion);
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

            return ['media_id' => (int) $media['id'], 'url' => (string) ($media['url'] ?? ''), 'alt' => $alt, 'why' => 'markanın kapak şablonu ('.self::STYLES[$style].')'];
        } catch (Throwable $e) {
            Log::warning('Brand cover could not be made; the site picture is used.', ['suggestion' => $suggestion->id, 'error' => $e->getMessage()]);

            return null;
        }
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

    /** The cover as a JPEG. */
    public function render(string $style, array $words): string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            throw new RuntimeException('GD with FreeType is not available.');
        }
        if ($style !== self::MOXIMU_REHBER) {
            throw new RuntimeException('Unknown cover style.');
        }
        $s = self::SCALE;
        $this->img = imagecreatetruecolor(self::WIDTH * $s, self::HEIGHT * $s);
        imagealphablending($this->img, true);
        imagesavealpha($this->img, false);
        $this->rect(0, 0, 800, 900, '#FFFFFF');
        $this->rect(800, 0, 1600, 900, '#F2F2EE');

        // Left: two-tone headline, the brand row and the lead, centred as one block.
        $headSize = 68;
        do {
            $head = $this->wrap($words['title'], 'InterDisplay-Light', $headSize, 660);
            $rest = $words['title_rest'] !== '' ? $this->wrap($words['title_rest'], 'InterDisplay-Light', $headSize, 660) : [];
            $headSize -= 4;
        } while (count($head) + count($rest) > 4 && $headSize > 40);
        $headSize += 4;
        $lineHead = (int) round($headSize * 1.12);
        $lead = $words['lead'] !== '' ? array_slice($this->wrap($words['lead'], 'Inter-SemiBold', 22, 640), 0, 2) : [];
        $leadRest = $words['lead_rest'] !== '' ? array_slice($this->wrap($words['lead_rest'], 'Inter-Regular', 22, 640), 0, max(0, 3 - count($lead))) : [];
        $height = (count($head) + count($rest)) * $lineHead + 54 + 38 + ($lead || $leadRest ? 26 + (count($lead) + count($leadRest)) * 32 : 0);
        $y = (int) max(80, (900 - $height) / 2);
        foreach ($head as $line) {
            $this->text($line, 'InterDisplay-Light', $headSize, 72, $y + $headSize, '#111827');
            $y += $lineHead;
        }
        foreach ($rest as $line) {
            $this->text($line, 'InterDisplay-Light', $headSize, 72, $y + $headSize, '#8A8F98');
            $y += $lineHead;
        }
        $y += 54;
        $this->mark(72, $y, 38);
        $x = $this->text('Moximu', 'Inter-Medium', 28, 122, $y + 30, '#111827');
        $this->text('Rehber', 'Inter-Regular', 28, $x + 12, $y + 30, '#8A8F98');
        $y += 38 + 26;
        foreach ($lead as $line) {
            $this->text($line, 'Inter-SemiBold', 22, 72, $y + 22, '#111827');
            $y += 32;
        }
        foreach ($leadRest as $line) {
            $this->text($line, 'Inter-Regular', 22, 72, $y + 22, '#5B6270');
            $y += 32;
        }

        // Right: the topic, the reader's question, the disc with the mark, Moximu's answer and the call.
        if ($words['tag'] !== '') {
            $this->text(mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], self::cut($words['tag'], 40)), 'UTF-8'), 'Inter-SemiBold', 16, 872, 88, '#0F5A52');
        }
        $q = array_slice($this->wrap($words['question'], 'Inter-Medium', 21, 560), 0, 2);
        $qWidth = max(array_map(fn (string $l): int => $this->width($l, 'Inter-Medium', 21), $q ?: [''])) + 46;
        $this->shadow(872, 126, 872 + $qWidth, 126 + 28 + count($q) * 30, 18);
        $this->rounded(872, 126, 872 + $qWidth, 126 + 28 + count($q) * 30, 18, '#111827');
        foreach ($q as $i => $line) {
            $this->text($line, 'Inter-Medium', 21, 895, 126 + 14 + 22 + $i * 30, '#FFFFFF');
        }
        $this->disc(1200, 450, 240, '#F7CE3C');
        $this->rounded(1120, 370, 1280, 530, 34, '#FDCF3C');
        $this->mark(1136, 386, 128);
        $a = $words['answer'] !== '' ? array_slice($this->wrap('Moximu: '.$words['answer'], 'Inter-Regular', 21, 316), 0, 4) : [];
        if ($a !== []) {
            $top = 470;
            $bottom = $top + 32 + count($a) * 30;
            $this->shadow(1168, $top, 1528, $bottom, 18);
            $this->rounded(1168, $top, 1528, $bottom, 18, '#FFFFFF');
            foreach ($a as $i => $line) {
                $ly = $top + 16 + 22 + $i * 30;
                if ($i === 0 && str_starts_with($line, 'Moximu:')) {
                    $x = $this->text('Moximu:', 'Inter-Medium', 21, 1190, $ly, '#0F5A52');
                    $this->text(trim(mb_substr($line, 7)), 'Inter-Regular', 21, $x + 7, $ly, '#111827');
                } else {
                    $this->text($line, 'Inter-Regular', 21, 1190, $ly, '#111827');
                }
            }
        }
        $label = 'Rehberi okuyun  ›';
        $w = $this->width($label, 'Inter-Medium', 24) + 88;
        $this->rounded((int) (1200 - $w / 2), 756, (int) (1200 + $w / 2), 828, 36, '#0F5A52');
        $this->text($label, 'Inter-Medium', 24, (int) (1200 - $w / 2 + 44), 801, '#FFFFFF');

        $out = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($out, $this->img, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * $s, self::HEIGHT * $s);
        ob_start();
        imagejpeg($out, null, 88);

        return (string) ob_get_clean();
    }

    /* ---------------------------------------------------------------- text */

    /** @return array{0: string, 1: string} "SEO nasıl ölçülür? Dört rakamla." → black and grey part */
    private static function splitTitle(string $title): array
    {
        if (preg_match('/^(.{12,}?[?:])\s+(\S.{3,})$/u', $title, $m) === 1) {
            return [rtrim($m[1], ':'), $m[2]];
        }

        return [$title, ''];
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
        [$x1, $y1, $x2, $y2, $r] = [$x1 * $s, $y1 * $s, $x2 * $s, $y2 * $s, min($r * $s, (int) (($y2 - $y1) * $s / 2))];
        $c = $this->color($color, $alpha);
        imagefilledrectangle($this->img, $x1 + $r, $y1, $x2 - $r, $y2, $c);
        imagefilledrectangle($this->img, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r, $c);
        imagefilledrectangle($this->img, $x2 - $r + 1, $y1 + $r, $x2, $y2 - $r, $c);
        foreach ([[$x1 + $r, $y1 + $r, 180], [$x2 - $r, $y1 + $r, 270], [$x1 + $r, $y2 - $r, 90], [$x2 - $r, $y2 - $r, 0]] as [$cx, $cy, $start]) {
            imagefilledarc($this->img, $cx, $cy, $r * 2, $r * 2, $start, $start + 90, $c, IMG_ARC_PIE);
        }
    }

    /** A soft drop shadow under a card: a few faint, growing layers. */
    private function shadow(int $x1, int $y1, int $x2, int $y2, int $r): void
    {
        foreach ([[28, 126], [20, 125], [12, 124], [4, 123]] as [$grow, $alpha]) {
            $this->rounded($x1 - $grow / 2, $y1 + 6 - $grow / 4, $x2 + $grow / 2, $y2 + 6 + $grow / 2, $r + $grow / 2, '#0B1220', $alpha);
        }
    }

    private function disc(int $cx, int $cy, int $r, string $color): void
    {
        $s = self::SCALE;
        imagefilledellipse($this->img, $cx * $s, $cy * $s, $r * 2 * $s, $r * 2 * $s, $this->color($color));
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
