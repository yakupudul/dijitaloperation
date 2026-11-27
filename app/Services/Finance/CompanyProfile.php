<?php

namespace App\Services\Finance;

use App\Services\Access\StartLink;
use App\Support\Finance\TaxNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Şirketimiz (yakup 2026-11-27 "vergi levhası gibi şirket bilgilerini girebileceğimiz alan"): the agency's own
 * invoicing identity in one place. Unvan, vergi no, vergi dairesi, MERSİS, ticaret sicil, adres, KEP, e-Fatura
 * mükellefiyeti and the vergi levhası file. The vergi no is Finans' own_tax_no (the Gmail filter reads it) and the
 * bank account is the start link's (the contract and the customer's payment page read it), so each fact has one home.
 */
final class CompanyProfile
{
    public const array KINDS = ['sahis' => 'Şahıs şirketi', 'limited' => 'Limited şirket', 'anonim' => 'Anonim şirket'];

    public const array INVOICE_KINDS = ['e_arsiv' => 'e-Arşiv fatura', 'e_fatura' => 'e-Fatura mükellefi'];

    /** What an invoice and a payment request cannot do without. */
    public const array REQUIRED = ['title' => 'Unvan', 'tax_no' => 'Vergi / TC kimlik no', 'tax_office' => 'Vergi dairesi', 'address' => 'Adres', 'iban' => 'IBAN', 'certificate' => 'Vergi levhası'];

    public const array TEXT = ['title', 'kind', 'tax_office', 'mersis', 'trade_registry', 'address', 'city', 'phone', 'email', 'kep', 'invoice_kind', 'web'];

    public function __construct(private readonly FinanceSettings $settings, private readonly StartLink $start) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $kept = (array) ($this->settings->rawCompany());
        $out = [];
        foreach (self::TEXT as $key) {
            $out[$key] = trim((string) ($kept[$key] ?? ''));
        }
        $out['tax_no'] = (string) $this->settings->get('own_tax_no');
        $bank = $this->start->bank();
        $out['iban'] = $bank['iban'];
        $out['bank'] = $bank['bank'];
        $out['holder'] = $bank['holder'];
        $out['certificate'] = (array) ($kept['certificate'] ?? []);

        return $out;
    }

    /** @return list<string> labels of what is still empty */
    public function missing(): array
    {
        $all = $this->all();
        $out = [];
        foreach (self::REQUIRED as $key => $label) {
            $value = $key === 'certificate' ? ($all['certificate']['path'] ?? null) : $all[$key];
            if (blank($value)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $input */
    public function save(array $input): void
    {
        $tax = (string) preg_replace('/\D+/', '', (string) ($input['tax_no'] ?? ''));
        $mersis = (string) preg_replace('/\D+/', '', (string) ($input['mersis'] ?? ''));
        $iban = mb_strtoupper((string) preg_replace('/\s+/', '', (string) ($input['iban'] ?? '')));
        $errors = [];
        if (($taxError = TaxNumber::error($tax)) !== null) {
            $errors['tax_no'] = $taxError;
        }
        if ($mersis !== '' && strlen($mersis) !== 16) {
            $errors['mersis'] = 'MERSİS no 16 hanedir.';
        }
        if ($iban !== '' && preg_match('/^TR\d{24}$/', $iban) !== 1) {
            $errors['iban'] = 'IBAN TR ile başlar ve 26 hanedir.';
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'E-posta adresi geçerli değil.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $kept = $this->settings->rawCompany();
        foreach (self::TEXT as $key) {
            $value = mb_substr(trim((string) ($input[$key] ?? '')), 0, $key === 'address' ? 400 : 180);
            $kept[$key] = $key === 'mersis' ? $mersis : $value;
        }
        if (! isset(self::KINDS[$kept['kind']])) {
            $kept['kind'] = '';
        }
        if (! isset(self::INVOICE_KINDS[$kept['invoice_kind']])) {
            $kept['invoice_kind'] = '';
        }
        // The unvan is one more name that marks an invoice as ours when Gmail papers are sorted.
        $names = array_values(array_unique(array_filter([...(array) $this->settings->get('own_names'), mb_strtolower((string) $kept['title'])])));
        $this->settings->put(['company' => $kept, 'own_tax_no' => $tax, 'own_names' => $names]);
        $this->start->save((string) ($input['holder'] ?? ''), $iban, (string) ($input['bank'] ?? ''), $this->start->contractText());
    }

    public function keepCertificate(UploadedFile $file): void
    {
        $path = self::storeCertificate($file, 'sirket');
        $kept = $this->settings->rawCompany();
        if (filled($kept['certificate']['path'] ?? null) && $kept['certificate']['path'] !== $path) {
            Storage::disk(FinanceDocuments::DISK)->delete((string) $kept['certificate']['path']);
        }
        $kept['certificate'] = ['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 160), 'mime' => (string) $file->getMimeType(), 'at' => now()->toIso8601String()];
        $this->settings->put(['company' => $kept]);
    }

    /** The block to paste wherever the company has to be written out (a form, a customer who asks, the muhasebeci). */
    public function sheet(): string
    {
        $a = $this->all();
        $lines = [
            'Unvan' => $a['title'],
            'Vergi dairesi' => $a['tax_office'],
            strlen($a['tax_no']) === 11 ? 'TC kimlik no' : 'Vergi no' => $a['tax_no'],
            'MERSİS no' => $a['mersis'],
            'Ticaret sicil no' => $a['trade_registry'],
            'Adres' => trim($a['address'].($a['city'] !== '' ? ' '.$a['city'] : '')),
            'Telefon' => $a['phone'],
            'E-posta' => $a['email'],
            'KEP' => $a['kep'],
            'Banka' => $a['bank'],
            'Hesap sahibi' => $a['holder'],
            'IBAN' => self::formatIban($a['iban']),
        ];

        return collect($lines)->filter(fn (string $v): bool => $v !== '')->map(fn (string $v, string $k): string => $k.': '.$v)->implode("\n");
    }

    public static function formatIban(string $iban): string
    {
        return trim(implode(' ', str_split($iban, 4)));
    }

    /** A vergi levhası (PDF or photo) on the private disk; never public. */
    public static function storeCertificate(UploadedFile $file, string $owner): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['certificate' => 'Vergi levhası PDF ya da fotoğraf olmalı, en çok 10 MB.']);
        }
        $path = 'finance/levha/'.$owner.'-'.Str::random(12).'.'.$ext;
        Storage::disk(FinanceDocuments::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }
}
