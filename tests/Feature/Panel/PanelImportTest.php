<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\ImportPage;
use App\Livewire\Panel\LeadsPage;
use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelImport;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelRequest;
use App\Panel\Support\SheetReader;
use App\Panel\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/** Sürüm 2: a list from Excel shows what it will do first, never doubles a phone, and can be taken back whole. */
class PanelImportTest extends PanelTestCase
{
    private function xlsx(array $head, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'panel-sheet');
        Xlsx::write($path, $head, $rows);

        return UploadedFile::fake()->createWithContent('liste.xlsx', (string) file_get_contents($path));
    }

    public function test_the_reader_reads_xlsx_and_turkish_csv(): void
    {
        $file = $this->xlsx(['Ad soyad', 'Telefon'], [['Çağla Öztürk', '0532 000 11 22'], ['', ''], ['Işıl Şen', 5321112233]]);
        $this->assertSame([['Ad soyad', 'Telefon'], ['Çağla Öztürk', '0532 000 11 22'], ['Işıl Şen', '5321112233']], SheetReader::read($file->getRealPath(), 'liste.xlsx'));
        $csv = tempnam(sys_get_temp_dir(), 'panel-csv');
        file_put_contents($csv, iconv('UTF-8', 'CP1254', "Ad;Telefon\r\nGülşah Çelik;0533 000 11 22\r\n"));
        $this->assertSame([['Ad', 'Telefon'], ['Gülşah Çelik', '0533 000 11 22']], SheetReader::read($csv, 'liste.csv'));
    }

    public function test_a_list_is_previewed_written_once_and_taken_back(): void
    {
        $this->enter($this->managerA, $this->brandA);
        $before = PanelPerson::query()->count();
        $file = $this->xlsx(['Ad soyad', 'Telefon', 'Konu', 'Kaynak', 'Not', 'Tarih', 'Renk'], [
            ['Cem Yeni', '0535 000 00 01', 'Bilgi', 'Tavsiye', 'Eski defterden', '03.02.2026', 'mavi'],
            ['Ayşe A.', '+90 532 111 22 33', 'Başka konu', '', 'Yeniden aradı', '', ''],
            ['Cem Yeni', '5350000001', '', '', '', '', ''],
            ['Telefonsuz', '', '', '', '', '', ''],
            ['Kısa', '12', '', '', '', '', ''],
        ]);
        $page = Livewire::test(ImportPage::class)->set('upload', $file)->assertHasNoErrors()
            ->assertSet('map', [0 => 'name', 1 => 'phone', 2 => 'interest', 3 => 'source', 4 => 'note', 5 => 'date', 6 => ''])
            ->assertSee('Alınmayacak satır')->assertSee('5. satır: Telefon boş');
        $this->assertSame($before, PanelPerson::query()->count(), 'the preview writes nothing');
        $page->set('map.1', '')->call('run')->assertHasErrors('map')->set('map.1', 'phone')->call('run')->assertHasNoErrors();

        $import = PanelImport::query()->where('kind', PanelImport::LEADS)->firstOrFail();
        $this->assertSame(['new' => 1, 'updated' => 2, 'bad' => 2, 'requests' => 1], $import->stats);
        $this->assertFileDoesNotExist($import->file());
        $cem = PanelPerson::query()->where('phone_key', '5350000001')->firstOrFail();
        $this->assertSame(['Cem Yeni', 'referral', 'Bilgi', '2026-02-03'], [$cem->name, $cem->source, $cem->interest, $cem->created_at->timezone('Europe/Istanbul')->format('Y-m-d')]);
        $ayse = $this->personA->fresh();
        $this->assertSame(['Ayşe Akasya', 'Kontrol'], [$ayse->name, $ayse->interest], 'a known person keeps what was already written');
        $this->assertTrue(PanelEvent::query()->where('person_id', $ayse->id)->where('type', PanelEvent::NOTE)->get()->contains(fn (PanelEvent $e): bool => $e->body === 'Yeniden aradı'));
        Livewire::test(LeadsPage::class)->assertSee('Cem Yeni')->assertSee('ice-aktar');

        // The other brand has neither the people nor the upload; undo removes exactly what the file added.
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(LeadsPage::class)->assertDontSee('Cem Yeni');
        Livewire::test(ImportPage::class)->assertDontSee('liste.xlsx')->call('undo', $import->id)->assertNotFound();
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(ImportPage::class)->assertSee('liste.xlsx')->call('undo', $import->id);
        $this->assertSame($before, PanelPerson::query()->count());
        $this->assertFalse(PanelEvent::query()->where('person_id', $ayse->id)->where('type', PanelEvent::NOTE)->exists());
        $this->assertSame('undone', $import->fresh()->status);
    }

    public function test_a_call_list_adds_calls_to_the_people_and_needs_the_permission(): void
    {
        $this->enter($this->managerA, $this->brandA);
        $file = $this->xlsx(['Arayan', 'Tarih', 'Not'], [['0532 111 22 33', '05.11.2026 14:30', 'Randevu sordu'], ['0536 000 00 09', '06.11.2026 09:00', '']]);
        Livewire::test(ImportPage::class)->set('kind', PanelImport::CALLS)->set('upload', $file)->call('run')->assertHasNoErrors();
        $calls = PanelRequest::query()->where('source', 'call')->orderBy('received_at')->get();
        $this->assertSame([$this->personA->id, '2026-11-05 14:30'], [$calls[0]->person_id, $calls[0]->received_at->timezone('Europe/Istanbul')->format('Y-m-d H:i')]);
        $this->assertSame(2, $calls->count());
        $this->assertNotNull($calls[0]->handled_at, 'an old call list does not fill the waiting list');

        $viewer = $this->member($this->brandA, 'izleyen@example.test', role: 'İzleyici');
        $this->asPanel($viewer)->get($this->url('/adaylar/ice-aktar'))->assertForbidden();
        $this->asPanel($viewer)->get($this->url('/adaylar/ice-aktar/ornek'))->assertForbidden();
        $this->asPanel($this->managerA)->get($this->url('/adaylar/ice-aktar/ornek?tur=calls'))->assertOk()->assertDownload('cagri-listesi-ornek.xlsx');
    }
}
