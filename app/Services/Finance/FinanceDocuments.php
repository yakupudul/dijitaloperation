<?php

namespace App\Services\Finance;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\FinanceDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * The finance files: an upload, a Gmail attachment or a WhatsApp dekont is kept once (the same bytes are never kept
 * twice), read, and filed by itself where it can be: an invoice we wrote finds its agreement by the buyer's vergi no
 * or name and the month's payment row; an expense takes the category its seller had last time. What could not be
 * filed waits as "new" for one look. A month's files leave as one ZIP.
 */
final class FinanceDocuments
{
    public const string DISK = 'local';

    /** Upload limit (bytes) and the file kinds taken. */
    public const int MAX_BYTES = 15_000_000;

    /** meta.reason of a mail paper that is not in our own name: only its mark is kept. */
    public const string NOT_MINE = 'not_mine';

    /** meta.reason of a mail paper taken before the rule that could not be read again yet: kept out of sight until it is known to be ours. */
    public const string UNVERIFIED = 'unverified';

    /** The same invoice sent twice in different files (a resent mail, a portal copy): the later one is only a mark. */
    public const string TWIN = 'twin';

    public const array EXTENSIONS = ['pdf', 'xml', 'jpg', 'jpeg', 'png', 'webp', 'zip', 'html'];

    public function __construct(private readonly FinanceReader $reader, private readonly FinanceSettings $settings) {}

    /**
     * Keeps a file and what it says. $hint: direction (out / in / receipt) when the operator already said it,
     * agreement_id / payment_id / brand_id when it was dropped on a row, source, external_id, from / subject of the mail.
     *
     * @param  array<string, mixed>  $hint
     * @return list<FinanceDocument> one per document found (a ZIP may hold several)
     */
    public function keep(string $bytes, string $name, string $mime = '', array $hint = []): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Dosya boş ya da çok büyük (en çok 15 MB).']);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip' || str_starts_with($bytes, "PK\x03\x04")) {
            return $this->keepZip($bytes, $name, $hint);
        }
        $one = $this->keepOne($bytes, $name, $mime, $hint);

        return $one !== null ? [$one] : [];
    }

    /**
     * A set of files that are one document (an e-fatura comes as XML + PDF): the XML is read, the PDF is what is kept
     * to look at.
     *
     * @param  list<array{bytes: string, name: string, mime?: string}>  $files
     * @param  array<string, mixed>  $hint
     * @return list<FinanceDocument>
     */
    public function keepSet(array $files, array $hint = []): array
    {
        $xml = collect($files)->first(fn (array $f): bool => strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) === 'xml');
        $read = $xml !== null ? $this->reader->xml($xml['bytes']) : null;
        $pdfs = collect($files)->filter(fn (array $f): bool => str_starts_with($f['bytes'], '%PDF'))->values();
        if ($read !== null) {
            $show = $pdfs->first() ?? $xml;
            $one = $this->keepOne($show['bytes'], $show['name'], $show['mime'] ?? '', $hint, $read);

            return $one !== null ? [$one] : [];
        }
        $out = [];
        foreach ($files as $i => $file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'xml' || ! in_array($ext, self::EXTENSIONS, true)) {
                continue; // an XML that is not an invoice, a signature file
            }
            $each = $hint;
            if (isset($hint['external_id'])) {
                $each['external_id'] = $hint['external_id'].':'.$i;
            }
            foreach ($this->keep($file['bytes'], $file['name'], $file['mime'] ?? '', $each) as $doc) {
                $out[] = $doc;
            }
        }

        return $out;
    }

    /** A line with no file: an expense or an invoice typed by hand. */
    public function manual(array $data, ?int $userId = null): FinanceDocument
    {
        $direction = in_array($data['direction'] ?? '', [FinanceDocument::IN, FinanceDocument::OUT], true) ? (string) $data['direction'] : FinanceDocument::IN;
        $amount = $this->money($data['amount'] ?? null);
        $who = trim((string) ($data['counterparty'] ?? ''));
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Tutarı yaz.']);
        }
        if ($who === '' && empty($data['agreement_id'])) {
            throw ValidationException::withMessages(['counterparty' => $direction === FinanceDocument::IN ? 'Nereye ödendiğini yaz.' : 'Kime kesildiğini seç.']);
        }
        $doc = new FinanceDocument(['direction' => $direction, 'source' => 'manual', 'status' => 'filed', 'created_by' => $userId]);
        $this->fill($doc, $data + ['counterparty' => $who, 'amount' => $amount]);
        $doc->save();

        return $doc;
    }

    /** The operator's corrections on a document; an invoice tied to an agreement teaches the agreement its vergi no. */
    public function update(FinanceDocument $doc, array $data): FinanceDocument
    {
        $this->fill($doc, $data);
        $doc->status = in_array($data['status'] ?? '', array_keys(FinanceDocument::STATUSES), true) ? (string) $data['status'] : 'filed';
        $doc->save();
        $this->learn($doc);

        return $doc;
    }

    public function delete(FinanceDocument $doc): void
    {
        if ($doc->hasFile() && ! FinanceDocument::query()->where('path', $doc->path)->where('id', '!=', $doc->id)->exists()) {
            Storage::disk(self::DISK)->delete((string) $doc->path);
        }
        $doc->delete();
    }

    public function bytes(FinanceDocument $doc): ?string
    {
        return $doc->hasFile() ? Storage::disk(self::DISK)->get((string) $doc->path) : null;
    }

    /**
     * The files of a set of documents as one ZIP, in folders by kind, with a CSV list at the top.
     *
     * @param  Collection<int, FinanceDocument>  $docs
     * @return string path of the temporary ZIP (the caller sends and removes it)
     */
    public function zip(Collection $docs, string $label): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Sunucuda ZIP desteği yok.');
        }
        $path = tempnam(sys_get_temp_dir(), 'finans').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP dosyası açılamadı.');
        }
        $folders = [FinanceDocument::OUT => 'kestigim-faturalar', FinanceDocument::IN => 'giderler', FinanceDocument::RECEIPT => 'dekontlar'];
        $csv = "\xEF\xBB\xBF".implode(';', ['Tür', 'Tarih', 'Belge no', 'Karşı taraf', 'Vergi no', 'Tutar', 'KDV', 'Para birimi', 'Kategori', 'Bağlı iş', 'Dosya'])."\n";
        foreach ($docs as $doc) {
            $file = '';
            $bytes = $this->bytes($doc);
            if ($bytes !== null) {
                $file = ($folders[$doc->direction] ?? 'diger').'/'.$doc->archiveName();
                $zip->addFromString($file, $bytes);
            }
            $csv .= implode(';', array_map(fn ($v): string => '"'.str_replace('"', '""', (string) $v).'"', [
                $doc->directionLabel(), $doc->doc_date?->format('d.m.Y'), $doc->doc_no, $doc->counterparty, $doc->counterparty_tax_no,
                $doc->amount !== null ? number_format((float) $doc->amount, 2, ',', '') : '', $doc->vat !== null ? number_format((float) $doc->vat, 2, ',', '') : '',
                $doc->currency, $doc->direction === FinanceDocument::IN ? $doc->categoryLabel() : '', $doc->agreement?->name(), $file,
            ]))."\n";
        }
        $zip->addFromString('liste-'.Str::slug($label).'.csv', $csv);
        $zip->close();

        return $path;
    }

    /**
     * Where an invoice we wrote belongs: the agreement whose vergi no is the buyer's, else the one whose payer's name
     * is the buyer's; then that agreement's payment row of the invoice's month (else its oldest open row).
     *
     * @return array{agreement: ?Agreement, payment: ?AgreementPayment}
     */
    public function place(?string $taxNo, ?string $name, ?CarbonImmutable $date, ?float $amount = null): array
    {
        $agreements = Agreement::query()->with('brand:id,name,customer_id', 'payments')->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('id')->get();
        $found = $taxNo !== null ? $agreements->where('tax_no', $taxNo) : collect();
        if ($found->isEmpty() && filled($name)) {
            $needle = self::key((string) $name);
            $found = $agreements->filter(function (Agreement $a) use ($needle): bool {
                foreach (array_filter([$a->brand?->name, $a->party, $a->brand?->customer?->name ?? null]) as $candidate) {
                    $key = self::key((string) $candidate);
                    if ($key !== '' && $needle !== '' && (str_contains($needle, $key) || str_contains($key, $needle) || similar_text($needle, $key) / max(strlen($needle), strlen($key)) > 0.8)) {
                        return true;
                    }
                }

                return false;
            });
        }
        if ($found->isEmpty()) {
            return ['agreement' => null, 'payment' => null];
        }
        // Several jobs of one payer: the one with a row in the invoice's month, else the running one.
        $period = $date?->format('Y-m');
        $pick = null;
        $row = null;
        foreach ($found as $agreement) {
            $candidate = $period !== null ? $agreement->payments->where('period', $period)->sortBy(fn (AgreementPayment $p): int => $amount !== null ? (int) abs($p->amount * $this->settings->vatFactor() - $amount) : 0)->first() : null;
            if ($candidate !== null) {
                [$pick, $row] = [$agreement, $candidate];
                break;
            }
        }
        $pick ??= $found->first();
        $row ??= $pick->payments->whereNull('paid_on')->sortBy('due_on')->first();

        return ['agreement' => $pick, 'payment' => $row];
    }

    /**
     * Is the paper in our own name: we wrote it (the seller is us) or it was written to us (the buyer is us).
     *
     * @param  array<string, mixed>  $read
     */
    public function inOurName(array $read): bool
    {
        return $this->ours($read['seller'] ?? null, $read['seller_tax_no'] ?? null) || $this->ours($read['buyer'] ?? null, $read['buyer_tax_no'] ?? null)
            || (isset($read['text']) && $this->mentionsUs((string) $read['text']));
    }

    /** A paper's own text names us: our vergi / TC no, or every word of one of our names (marks and case aside). */
    public function mentionsUs(string $text): bool
    {
        $own = $this->settings->all();
        if ($own['own_tax_no'] !== '' && str_contains((string) preg_replace('/\D+/', ' ', $text), $own['own_tax_no'])) {
            return true;
        }
        $key = self::key($text);
        foreach ($own['own_names'] as $mine) {
            $words = array_values(array_filter(array_map(fn (string $w): string => self::key($w), preg_split('/\s+/u', $mine) ?: [])));
            // "yakup" and "udul" both somewhere in the text is not enough for a long page: they must stand together.
            if ($words !== [] && str_contains($key, implode('', $words))) {
                return true;
            }
            if (count($words) > 1 && str_contains($key, implode('', array_reverse($words)))) {
                return true;
            }
        }

        return false;
    }

    /** Our vergi / TC no, or one of our names with all its words in the name (in any order: "UDÜL YAKUP"). */
    public function ours(?string $name, ?string $tax): bool
    {
        $own = $this->settings->all();
        if ($tax !== null && $own['own_tax_no'] !== '' && $tax === $own['own_tax_no']) {
            return true;
        }
        $key = self::key((string) $name);
        if ($key === '') {
            return false;
        }
        foreach ($own['own_names'] as $mine) {
            $words = array_values(array_filter(array_map(fn (string $w): string => self::key($w), preg_split('/\s+/u', $mine) ?: [])));
            if ($words !== [] && collect($words)->every(fn (string $w): bool => str_contains($key, $w))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mail papers taken before the rule "only in our own name": each is read once more; one that is not ours loses its
     * file and what was read, and stays only as a mark. An invoice we wrote is ours already.
     *
     * @return array{checked: int, dropped: int, left: int}
     */
    public function recheckMail(int $limit = 8): array
    {
        // Not looked at yet, or looked at while the reader was away (those wait out of sight with their file).
        $open = fn () => FinanceDocument::query()->where('source', 'gmail')->whereNull('meta->mine')
            ->where(fn ($q) => $q->where('status', '!=', 'ignored')->orWhere('meta->reason', self::UNVERIFIED));
        $held = $this->reader->held() !== null;
        $out = ['checked' => 0, 'dropped' => 0, 'left' => 0];
        // The least lately looked at first, so papers no rule can read do not hold the others back.
        foreach ($open()->orderBy('updated_at')->orderBy('id')->limit($held ? 200 : $limit)->get() as $doc) {
            $read = null;
            $mine = $doc->direction === FinanceDocument::OUT && $doc->status !== 'ignored' ? true : null;
            if ($mine === null) {
                $bytes = $this->bytes($doc);
                // The reader takes the text of a PDF by rule when the API cannot be used, so every paper is tried each time.
                $read = $bytes !== null ? $this->reader->read($bytes, (string) $doc->mime, (string) $doc->original_name, ['from' => data_get($doc->meta, 'from'), 'subject' => data_get($doc->meta, 'subject')]) : null;
                if ($read === null && $bytes !== null && strlen($bytes) <= FinanceReader::AI_MAX_BYTES) {
                    // Cannot be read now: until it is known to be ours it is not shown.
                    if ($doc->status !== 'ignored') {
                        $doc->forceFill(['meta' => ['reason' => self::UNVERIFIED, 'was' => $doc->status] + (array) $doc->meta, 'status' => 'ignored'])->save();
                    } else {
                        $doc->touch();
                    }

                    continue;
                }
                $mine = $read !== null && $this->inOurName($read);
            }
            $out['checked']++;
            if ($this->settle($doc, (bool) $mine, $read)) {
                continue;
            }
            $out['dropped']++;
        }
        $this->setAsideCopies();
        $out['left'] = $open()->count();
        $out['twins'] = $this->dropTwins();

        return $out;
    }

    /**
     * A mail paper whose owner is known now: ours comes into sight with what was read written on it; another's loses
     * its file and what was read and stays only as a mark. True when it was ours.
     *
     * @param  ?array<string, mixed>  $read
     */
    public function settle(FinanceDocument $doc, bool $mine, ?array $read): bool
    {
        if ($mine) {
            $meta = (array) $doc->meta;
            $was = (string) ($meta['was'] ?? $doc->status);
            unset($meta['reason'], $meta['was']);
            $doc->forceFill(['status' => $doc->status === 'ignored' ? $was : $doc->status, 'meta' => array_filter(['mine' => true, 'read_by' => $read['by'] ?? ($meta['read_by'] ?? null)]) + $meta]);
            if ($read !== null && $doc->counterparty === null && $doc->amount === null) {
                // A paper kept unread: what was read now is written on it.
                $this->classify($doc, $read, []);
                $this->secondCopy($doc);
            }
            $doc->save();
            if ($doc->wasChanged('counterparty')) {
                $this->learn($doc);
            }
            $this->dropTwins($doc->doc_no);

            return true;
        }
        if ($doc->hasFile() && ! FinanceDocument::query()->where('path', $doc->path)->where('id', '!=', $doc->id)->exists()) {
            Storage::disk(self::DISK)->delete((string) $doc->path);
        }
        $doc->forceFill(['status' => 'ignored', 'path' => null, 'counterparty' => null, 'counterparty_tax_no' => null, 'doc_no' => null, 'amount' => null, 'vat' => null,
            'agreement_id' => null, 'payment_id' => null, 'brand_id' => null, 'rebill' => false, 'vendor_key' => null, 'category' => null, 'note' => null,
            'meta' => ['reason' => self::NOT_MINE]])->save();

        return false;
    }

    /**
     * Mail papers no rule could read (a scan, a PDF without text), for a Claude subscription round to read through the
     * MCP tool `finance` (yakup 2026-11-13: "bu işlemi claude abonelik yapsın her zaman"): the mail it came with, the
     * text the PDF carries if any, and a link to the file that works for a day.
     *
     * @return list<array<string, mixed>>
     */
    public function unread(int $limit = 10): array
    {
        return FinanceDocument::query()->where('source', 'gmail')->where('meta->reason', self::UNVERIFIED)->orderBy('id')->limit(max(1, min(25, $limit)))->get()
            ->map(function (FinanceDocument $doc): array {
                $bytes = $this->bytes($doc);
                $text = $bytes !== null && str_starts_with($bytes, '%PDF') ? $this->reader->pdfText($bytes) : null;

                return ['id' => $doc->id, 'file' => $doc->original_name, 'mail_from' => data_get($doc->meta, 'from'), 'mail_subject' => data_get($doc->meta, 'subject'),
                    'text' => $text !== null ? mb_substr($text, 0, 6000) : null,
                    'file_url' => $bytes !== null ? URL::temporarySignedRoute('finance.paper', now()->addDay(), ['document' => $doc->id]) : null];
            })->all();
    }

    /** "ÖZEL DEMİDERM POLİKLİNİĞİ LTD. ŞTİ." → "ozeldemidermpoliklinigi": names compared without case, marks and company tails. */
    public static function key(string $name): string
    {
        $s = mb_strtolower(strtr($name, ['İ' => 'i', 'I' => 'ı']));
        $s = strtr($s, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $s = (string) preg_replace('/\b(ltd|sti|şti|a\.?s|anonim|limited|sirketi|sirket|san|tic|ve|inc|llc|gmbh|ireland|pte|corp|co)\b\.?/u', ' ', $s);

        return (string) preg_replace('/[^a-z0-9]+/', '', $s);
    }

    /**
     * @param  array<string, mixed>  $hint
     * @param  ?array<string, mixed>  $read  what was already read (an XML beside the file)
     */
    private function keepOne(string $bytes, string $name, string $mime, array $hint, ?array $read = null): ?FinanceDocument
    {
        $sha = hash('sha256', $bytes);
        $existing = FinanceDocument::query()->where('sha256', $sha)->first();
        if ($existing !== null) {
            return $existing; // the same file again (a second upload, the mail read twice)
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'Bu dosya türü alınmıyor (PDF, XML, fotoğraf ya da ZIP yükle).']);
        }
        $read ??= $this->reader->read($bytes, $mime, $name, ['from' => $hint['from'] ?? null, 'subject' => $hint['subject'] ?? null, 'hint' => $hint['context'] ?? null]);
        $source = (string) ($hint['source'] ?? 'upload');
        // From the mailbox only a paper in our own name is taken (yakup 2026-11-13: "fatura benim adıma düzenlenmemişse
        // kesinlikle alma"). One that could not be read is not taken either: the mail is read again later.
        if ($source === 'gmail' && $read === null && strlen($bytes) <= FinanceReader::AI_MAX_BYTES) {
            // Kept with its file but out of sight until a later read (the API, or a Claude subscription round through
            // the MCP tool `finance`) says whose paper it is.
            $path = 'finance/'.now('Europe/Istanbul')->format('Y/m').'/'.Str::uuid().'.'.$ext;
            Storage::disk(self::DISK)->put($path, $bytes);

            return FinanceDocument::query()->create([
                'source' => $source, 'status' => 'ignored', 'direction' => FinanceDocument::IN, 'doc_date' => now('Europe/Istanbul')->toDateString(), 'path' => $path,
                'original_name' => mb_substr($name, 0, 250), 'mime' => mb_substr($mime, 0, 120), 'size' => strlen($bytes), 'sha256' => $sha,
                'external_id' => isset($hint['external_id']) ? mb_substr((string) $hint['external_id'], 0, 190) : null,
                'meta' => array_filter(['from' => $hint['from'] ?? null, 'subject' => $hint['subject'] ?? null, 'reason' => self::UNVERIFIED, 'was' => 'new']),
            ]);
        }
        $notMine = $source === 'gmail' && ($read === null || ! $this->inOurName($read));
        // A mail attachment or a WhatsApp photo that is no finance document is not kept at all; only its mark stays, so
        // it is not read again.
        $notOurs = $notMine || ($read !== null && $read['kind'] === 'other' && $source !== 'upload');
        $onlyReceipts = ! empty($hint['only_receipts']) && ($read === null || $read['kind'] !== 'receipt');
        if ($onlyReceipts) {
            return null;
        }
        $doc = new FinanceDocument([
            'source' => $source, 'status' => $notOurs ? 'ignored' : 'new', 'original_name' => mb_substr($name, 0, 250), 'mime' => mb_substr($mime, 0, 120),
            'size' => strlen($bytes), 'sha256' => $sha, 'external_id' => isset($hint['external_id']) ? mb_substr((string) $hint['external_id'], 0, 190) : null,
            'created_by' => $hint['user_id'] ?? null, 'cost_usd' => (float) ($read['cost_usd'] ?? 0),
            'meta' => array_filter(['from' => $hint['from'] ?? null, 'subject' => $hint['subject'] ?? null, 'read_by' => $read['by'] ?? null, 'summary' => $read['summary'] ?? null,
                'reason' => $notMine ? self::NOT_MINE : null, 'mine' => $source === 'gmail' && ! $notOurs ? true : null]),
        ]);
        if ($notMine) {
            // Only the mark: no name, no amount, no file.
            $doc->direction = FinanceDocument::IN;
            $doc->doc_date = now('Europe/Istanbul')->toDateString();
            $doc->save();

            return $doc;
        }
        if (! $notOurs) {
            $path = 'finance/'.now('Europe/Istanbul')->format('Y/m').'/'.Str::uuid().'.'.$ext;
            Storage::disk(self::DISK)->put($path, $bytes);
            $doc->path = $path;
        }
        $this->classify($doc, $read, $hint);
        if ($source !== 'upload' && $this->secondCopy($doc)) {
            $doc->save();

            return $doc; // the same invoice came once more (a second mail, another file of it): not counted twice
        }
        $doc->save();
        $this->learn($doc);
        $this->dropTwins($doc->doc_no);

        return $doc->fresh() ?? $doc;
    }

    /**
     * Keeps one copy of each invoice: papers with the same number, side and amount but different bytes (the same
     * e-arşiv mailed twice) leave the later ones as hidden marks without a file. Returns how many were dropped.
     */
    public function dropTwins(?string $docNo = null): int
    {
        $shown = FinanceDocument::query()->where('status', '!=', 'ignored')->whereNotNull('doc_no')->where('doc_no', '!=', '')
            ->when($docNo !== null, fn ($q) => $q->where('doc_no', $docNo));
        if ($docNo === null && ! (clone $shown)->select('doc_no')->groupBy('doc_no')->havingRaw('count(*) > 1')->exists()) {
            return 0;
        }
        $dropped = 0;
        foreach ($shown->orderBy('id')->get()->groupBy(fn (FinanceDocument $d): string => $d->direction.'|'.$d->doc_no.'|'.number_format((float) $d->amount, 2, '.', '')) as $group) {
            foreach ($group->slice(1) as $twin) {
                if ($twin->hasFile() && ! FinanceDocument::query()->where('path', $twin->path)->where('id', '!=', $twin->id)->exists()) {
                    Storage::disk(self::DISK)->delete((string) $twin->path);
                }
                $twin->forceFill(['status' => 'ignored', 'path' => null, 'meta' => ['reason' => self::TWIN, 'twin_of' => $group->first()->id] + (array) $twin->meta])->save();
                $dropped++;
            }
        }

        return $dropped;
    }

    /**
     * The same paper a second time: same way, same number, same other side as one already in sight. It is set aside
     * (Faturalar › Yok sayılanlar, with its file, "Geri al" brings it back) and says which paper it repeats. One the
     * operator brought back is not set aside again.
     */
    private function secondCopy(FinanceDocument $doc): bool
    {
        $no = self::key((string) $doc->doc_no);
        if ($no === '' || $doc->status === 'ignored' || data_get($doc->meta, 'same_as') !== null) {
            return false;
        }
        $first = FinanceDocument::query()->where('direction', $doc->direction)->where('status', '!=', 'ignored')->whereNotNull('doc_no')
            ->when($doc->exists, fn ($q) => $q->where('id', '<', $doc->id))->orderBy('id')->get()
            ->first(function (FinanceDocument $other) use ($doc, $no): bool {
                if (self::key((string) $other->doc_no) !== $no) {
                    return false;
                }
                if (filled($doc->counterparty_tax_no) && filled($other->counterparty_tax_no)) {
                    return $doc->counterparty_tax_no === $other->counterparty_tax_no;
                }

                return self::key((string) $doc->counterparty) === self::key((string) $other->counterparty);
            });
        if ($first === null) {
            return false;
        }
        $doc->status = 'ignored';
        $doc->meta = ['same_as' => $first->id] + (array) $doc->meta;

        return true;
    }

    /**
     * Papers already in sight twice (taken before the rule above): the later copy is set aside.
     *
     * @return int how many were set aside
     */
    public function setAsideCopies(): int
    {
        $count = 0;
        $numbers = FinanceDocument::query()->where('source', '!=', 'upload')->where('status', '!=', 'ignored')->whereNotNull('doc_no')->orderByDesc('id')->limit(500)->get();
        foreach ($numbers as $doc) {
            if ($this->secondCopy($doc)) {
                $doc->save();
                $count++;
            }
        }

        return $count;
    }

    /** @return list<FinanceDocument> */
    private function keepZip(string $bytes, string $name, array $hint): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'Sunucuda ZIP açılamıyor; dosyaları tek tek yükle.']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'finzip');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $files = [];
        try {
            if ($zip->open($tmp) !== true) {
                throw ValidationException::withMessages(['file' => 'ZIP dosyası açılamadı.']);
            }
            $total = 0;
            for ($i = 0; $i < min($zip->numFiles, 60); $i++) {
                $stat = $zip->statIndex($i);
                $inner = (string) ($stat['name'] ?? '');
                $ext = strtolower(pathinfo($inner, PATHINFO_EXTENSION));
                if ($stat === false || str_ends_with($inner, '/') || ! in_array($ext, ['pdf', 'xml', 'jpg', 'jpeg', 'png'], true) || (int) $stat['size'] > self::MAX_BYTES) {
                    continue;
                }
                $total += (int) $stat['size'];
                if ($total > 4 * self::MAX_BYTES) {
                    break; // a ZIP that opens far bigger than it looks
                }
                $content = $zip->getFromIndex($i);
                if (is_string($content) && $content !== '') {
                    $files[] = ['bytes' => $content, 'name' => basename($inner)];
                }
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }
        // Files that share a name (fatura.xml + fatura.pdf) are one document; a ZIP of many invoices gives many.
        $out = [];
        foreach (collect($files)->groupBy(fn (array $f): string => pathinfo($f['name'], PATHINFO_FILENAME)) as $stem => $set) {
            $each = $hint;
            if (isset($hint['external_id'])) {
                $each['external_id'] = $hint['external_id'].':'.Str::limit((string) $stem, 40, '');
            }
            foreach ($this->keepSet($set->all(), $each) as $doc) {
                $out[] = $doc;
            }
        }

        return $out;
    }

    /**
     * Says which way the document goes and files it. We are the seller → an invoice we wrote; a dekont → a receipt;
     * anything else → an expense.
     *
     * @param  ?array<string, mixed>  $read
     * @param  array<string, mixed>  $hint
     */
    private function classify(FinanceDocument $doc, ?array $read, array $hint): void
    {
        $own = $this->settings->all();
        $isOurs = fn (?string $name, ?string $tax): bool => $this->ours($name, $tax);
        $direction = in_array($hint['direction'] ?? '', array_keys(FinanceDocument::DIRECTIONS), true) ? (string) $hint['direction'] : null;
        if ($read !== null) {
            $direction ??= $read['kind'] === 'receipt' ? FinanceDocument::RECEIPT
                : ($isOurs($read['seller'], $read['seller_tax_no']) ? FinanceDocument::OUT : FinanceDocument::IN);
            // Our own vergi no is not typed yet: an invoice whose buyer is one of our payers is one we wrote, and its
            // seller's number is ours from now on.
            if (! isset($hint['direction']) && $direction === FinanceDocument::IN && $read['kind'] === 'invoice' && $own['own_tax_no'] === ''
                && (filled($read['buyer_tax_no']) || filled($read['buyer']))
                && $this->place($read['buyer_tax_no'], $read['buyer'], null)['agreement'] !== null) {
                $direction = FinanceDocument::OUT;
                if ($read['seller_tax_no'] !== null) {
                    $this->settings->put(['own_tax_no' => $read['seller_tax_no']]);
                }
            }
            // The other side of the paper: the buyer of our invoice, the seller of an expense, the sender of a dekont.
            [$who, $tax] = $direction === FinanceDocument::OUT ? [$read['buyer'], $read['buyer_tax_no']] : [$read['seller'], $read['seller_tax_no']];
            if ($direction === FinanceDocument::IN && $isOurs($who, $tax) && filled($read['buyer']) && ! $isOurs($read['buyer'], $read['buyer_tax_no'])) {
                [$who, $tax] = [$read['buyer'], $read['buyer_tax_no']];
            }
            $doc->fill(['doc_no' => $read['doc_no'], 'doc_date' => $read['doc_date'], 'counterparty' => $who, 'counterparty_tax_no' => $tax,
                'amount' => $read['amount'], 'vat' => $read['vat'], 'currency' => $read['currency']]);
        }
        $doc->direction = $direction ?? FinanceDocument::IN;
        $doc->doc_date ??= now('Europe/Istanbul')->toDateString();
        $doc->agreement_id = isset($hint['agreement_id']) ? (int) $hint['agreement_id'] : null;
        $doc->payment_id = isset($hint['payment_id']) ? (int) $hint['payment_id'] : null;
        $doc->brand_id = isset($hint['brand_id']) ? (int) $hint['brand_id'] : null;
        if ($doc->direction === FinanceDocument::IN) {
            $doc->vendor_key = self::key((string) $doc->counterparty) ?: null;
            $last = $doc->vendor_key !== null ? FinanceDocument::query()->where('direction', FinanceDocument::IN)->where('vendor_key', $doc->vendor_key)->whereNotNull('category')->orderByDesc('id')->first() : null;
            $doc->category = $last?->category ?? self::guessCategory((string) $doc->counterparty.' '.(string) data_get($doc->meta, 'summary').' '.(string) data_get($doc->meta, 'subject'));
            // What was paid for a customer last time is for the same customer this time.
            if ($last !== null && $doc->brand_id === null && $last->rebill) {
                $doc->brand_id = $last->brand_id;
                $doc->rebill = true;
            }
            if ($last !== null && $read !== null) {
                $doc->status = 'filed'; // a seller already known: nothing to look at
            }
        } elseif ($doc->agreement_id === null) {
            $place = $this->place($doc->counterparty_tax_no, $doc->counterparty, $doc->doc_date !== null ? CarbonImmutable::parse($doc->doc_date) : null, $doc->amount !== null ? (float) $doc->amount : null);
            if ($place['agreement'] === null && isset($hint['customer_id'])) {
                // A dekont from a customer's WhatsApp chat: the payer is the customer, whatever name the bank printed.
                $agreement = Agreement::query()->where('customer_id', (int) $hint['customer_id'])->whereIn('status', ['active', 'paused'])->orderByDesc('id')->first();
                $place = ['agreement' => $agreement, 'payment' => $agreement?->payments()->whereNull('paid_on')->orderBy('due_on')->first()];
            }
            $doc->agreement_id = $place['agreement']?->id;
            $doc->brand_id ??= $place['agreement']?->brand_id;
            if ($doc->direction === FinanceDocument::RECEIPT) {
                $doc->payment_id = $place['agreement']?->payments()->whereNull('paid_on')->orderBy('due_on')->orderBy('id')->first()?->id;
            } else {
                $doc->payment_id = $place['payment']?->id;
                if ($place['agreement'] !== null && $read !== null && ($read['by'] ?? '') === 'xml' && $doc->counterparty_tax_no !== null && $place['agreement']->tax_no === $doc->counterparty_tax_no) {
                    $doc->status = 'filed'; // found by vergi no from the XML: certain
                }
            }
        }
    }

    private function fill(FinanceDocument $doc, array $data): void
    {
        foreach (['doc_no' => 80, 'counterparty' => 200, 'note' => 500] as $key => $max) {
            if (array_key_exists($key, $data)) {
                $doc->{$key} = ($v = trim((string) $data[$key])) !== '' ? mb_substr($v, 0, $max) : null;
            }
        }
        if (array_key_exists('doc_date', $data)) {
            $doc->doc_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['doc_date']) === 1 ? (string) $data['doc_date'] : ($doc->doc_date ?? now('Europe/Istanbul')->toDateString());
        }
        $doc->doc_date ??= now('Europe/Istanbul')->toDateString();
        foreach (['amount', 'vat'] as $key) {
            if (array_key_exists($key, $data)) {
                $doc->{$key} = $this->money($data[$key]);
            }
        }
        if (array_key_exists('currency', $data)) {
            $doc->currency = preg_match('/^[A-Z]{3}$/', strtoupper((string) $data['currency'])) === 1 ? strtoupper((string) $data['currency']) : 'TRY';
        }
        if (array_key_exists('direction', $data) && isset(FinanceDocument::DIRECTIONS[$data['direction']])) {
            $doc->direction = (string) $data['direction'];
        }
        if (array_key_exists('category', $data)) {
            $doc->category = isset(FinanceDocument::CATEGORIES[$data['category']]) ? (string) $data['category'] : null;
        }
        if (array_key_exists('counterparty_tax_no', $data)) {
            $digits = (string) preg_replace('/\D+/', '', (string) $data['counterparty_tax_no']);
            $doc->counterparty_tax_no = in_array(strlen($digits), [10, 11], true) ? $digits : null;
        }
        if (array_key_exists('agreement_id', $data)) {
            $agreement = filled($data['agreement_id']) ? Agreement::query()->find((int) $data['agreement_id']) : null;
            $doc->agreement_id = $agreement?->id;
            if ($doc->direction !== FinanceDocument::IN) {
                $doc->brand_id = $agreement?->brand_id;
                $row = filled($data['payment_id'] ?? null) ? $agreement?->payments()->whereKey((int) $data['payment_id'])->first() : null;
                $row ??= $agreement?->payments()->where('period', CarbonImmutable::parse($doc->doc_date)->format('Y-m'))->first();
                $doc->payment_id = $row?->id;
            }
        }
        if (array_key_exists('brand_id', $data) && $doc->direction === FinanceDocument::IN) {
            $doc->brand_id = filled($data['brand_id']) ? Brand::query()->whereKey((int) $data['brand_id'])->value('id') : null;
        }
        if (array_key_exists('rebill', $data)) {
            $doc->rebill = (bool) $data['rebill'] && $doc->direction === FinanceDocument::IN;
        }
        if ($doc->direction === FinanceDocument::IN) {
            $doc->vendor_key = self::key((string) $doc->counterparty) ?: null;
            $doc->category ??= 'diger';
        }
    }

    /** An invoice the operator tied to an agreement teaches the agreement its payer's vergi no, so the next one files itself. */
    private function learn(FinanceDocument $doc): void
    {
        if ($doc->direction === FinanceDocument::OUT && $doc->status === 'filed' && $doc->agreement_id !== null && $doc->counterparty_tax_no !== null) {
            Agreement::query()->whereKey($doc->agreement_id)->whereNull('tax_no')->update(['tax_no' => $doc->counterparty_tax_no]);
        }
    }

    private static function guessCategory(string $text): string
    {
        $t = mb_strtolower($text);
        foreach ([
            'yapay_zeka' => ['anthropic', 'openai', 'claude', 'chatgpt', 'midjourney', 'elevenlabs'],
            'reklam' => ['google ads', 'meta platforms', 'facebook', 'reklam', 'adwords', 'tiktok', 'linkedin'],
            'sunucu' => ['hetzner', 'digitalocean', 'natro', 'isimtescil', 'godaddy', 'namecheap', 'turhost', 'cloudflare', 'hosting', 'sunucu', 'alan ad', 'domain', 'vps', 'contabo', 'hostinger', 'veridyen', 'radore', 'turkticaret', 'metunic', 'odtü geliştirme', 'odtu gelistirme', 'güzel hosting', 'guzel hosting'],
            'iletisim' => ['turkcell', 'vodafone', 'türk telekom', 'turk telekom', 'superonline', 'turknet', 'netgsm'],
            'vergi' => ['mali müşavir', 'muhasebe', 'smmm', 'vergi dairesi'],
            'yazilim' => ['google workspace', 'google cloud', 'microsoft', 'adobe', 'elementor', 'envato', 'canva', 'figma', 'notion', 'github', 'wp rocket', 'rank math', 'yoast', 'semrush', 'ahrefs', 'apple', 'paytr', 'iyzico', 'lisans', 'abonelik', 'subscription'],
        ] as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($t, $word)) {
                    return $category;
                }
            }
        }

        return 'diger';
    }

    /** "1.250,50", "1250.5", 1250 → 1250.5 */
    private function money(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $s = (string) preg_replace('/[^\d,.\-]/', '', $s);
        if (str_contains($s, ',')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s) === 1) {
            $s = str_replace('.', '', $s); // 12.000 = on iki bin
        }

        return is_numeric($s) ? round((float) $s, 2) : null;
    }
}
