<?php

namespace Tests\Feature\Brand;

use App\Ai\Agents\Site\EpicrisisFactsAgent;
use App\Ai\Agents\Site\EpicrisisSynthesisAgent;
use App\Livewire\Operator\Portfolio\BrandEpicrisisTab;
use App\Livewire\Operator\Portfolio\EpicrisisQuestions;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\SaveBrandFact;
use App\Models\BrandEpicrisis;
use App\Models\BrandEpicrisisItem;
use App\Models\BrandExpert;
use App\Models\BrandMemory;
use App\Models\Page;
use App\Models\PromptVersion;
use App\Services\Brand\BrandFacts;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Epicrisis\Epicrisis;
use App\Services\Epicrisis\EpicrisisClaims;
use App\Services\Epicrisis\EpicrisisFacts;
use App\Services\Epicrisis\EpicrisisLessons;
use App\Services\Epicrisis\EpicrisisStore;
use App\Services\Epicrisis\EpicrisisSynthesis;
use App\Services\Mcp\BrandBriefing;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiRouteKeys;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * Marka epikrizi (yakup, 2026-10-16 "AI yazıp doğrudan kullansın, bu şekilde yap"): facts by rules and by a grounded
 * AI reading, confidence / conflicts / expiry, sector questions, the synthesis held by its references, the slice every
 * AI step reads, the claim check, the Epikriz tab, the Bugün questions box and the chat (MCP) fact.
 */
final class EpicrisisTest extends SiteTestCase
{
    private function store(): EpicrisisStore
    {
        return app(EpicrisisStore::class);
    }

    /** @param  array<string, mixed>  $extra */
    private function record(string $field, string $value, string $source = 'site', ?string $scope = null, array $extra = []): void
    {
        // One producer per line, so writing the next line does not let this one go.
        $this->store()->sync($this->brand, BrandEpicrisisItem::FACT, [array_merge(['field' => $field, 'value' => $value, 'scope' => $scope, 'source_kind' => $source, 'source_ref' => 'https://panorama.com.tr/'], $extra)],
            'test-'.$source.'-'.md5($field.$value));
    }

    private function item(string $field, ?string $layer = BrandEpicrisisItem::FACT): ?BrandEpicrisisItem
    {
        return BrandEpicrisisItem::query()->where('brand_id', $this->brand->id)->where('layer', $layer)->where('field', $field)->orderBy('id')->first();
    }

    public function test_rule_facts_come_from_the_brand_records_and_its_own_pages_and_two_sources_make_a_fact_verified(): void
    {
        BrandExpert::query()->create(['brand_id' => $this->brand->id, 'name' => 'Dt. Ayşe Kara', 'title' => 'Ağız ve Diş Cerrahı', 'is_default' => true]);
        BrandFacts::write($this->brand, 'experts', "Dt. Ayşe Kara\nDt. Mehmet Su", $this->admin->id);
        $this->page('/', 'Panorama Ankara', ['content_text' => 'Randevu için 0312 123 45 67 numarasını arayın.']);
        $this->page('/iletisim/', 'İletişim', ['content_text' => 'Telefon: +90 312 1234567 · E-posta: Bilgi@Panorama.com.tr']);
        $this->page('/blog/rastgele/', 'Rastgele yazı', ['content_text' => 'Arayın 0312 999 88 77']);

        app(Epicrisis::class)->build($this->brand);

        $services = BrandEpicrisisItem::query()->where('brand_id', $this->brand->id)->where('field', 'service')->pluck('confidence', 'value');
        $this->assertSame('verified', $services['Diş İmplantı'] ?? null, 'a locked service is the operator\'s');
        $this->assertSame('Çankaya, Ankara, TR', $this->item('branch')->value);
        $phones = BrandEpicrisisItem::query()->where('brand_id', $this->brand->id)->where('field', 'phone')->get();
        $this->assertCount(1, $phones, 'the same phone in two forms is one line; a blog page is not a key page');
        $this->assertSame('data', $phones->first()->confidence, 'two pages of one site are one source');
        $this->record('phone', '0312-123-4567', 'gbp');
        $this->store()->reconcile($this->brand);
        $this->assertSame('verified', $phones->first()->fresh()->confidence, 'the site and the profile say it');
        $this->assertSame('bilgi@panorama.com.tr', $this->item('email')->value);
        $experts = BrandEpicrisisItem::query()->where('brand_id', $this->brand->id)->where('field', 'expert')->pluck('source_kind', 'value');
        $this->assertSame('operator', $experts['Dt. Mehmet Su']);
        $head = BrandEpicrisis::query()->where('brand_id', $this->brand->id)->first();
        $this->assertNotNull($head->summary);
        $this->assertStringContainsString('Panorama Ankara — epikriz', $head->summary);
        $this->assertGreaterThan(0, $head->fill_percent);

        // The pages no longer show the number: the rules let it go; the profile still holds it, the e-mail nobody holds.
        Page::query()->update(['content_text' => 'Yeni metin.']);
        app(Epicrisis::class)->build($this->brand);
        $this->assertNotContains('rules', data_get($phones->first()->fresh()->detail, 'producers'));
        $this->assertSame(BrandEpicrisisItem::ACTIVE, $phones->first()->fresh()->status);
        $this->assertSame(BrandEpicrisisItem::DROPPED, $this->item('email')->status);
    }

    public function test_a_conflict_is_kept_out_of_ai_work_and_asked_and_the_answer_wins(): void
    {
        $this->record('experience', '2008\'den beri hizmet veriyor', 'site');
        $this->record('experience', '2012\'den beri hizmet veriyor', 'gbp');
        app(Epicrisis::class)->build($this->brand);

        $this->assertSame(2, BrandEpicrisisItem::query()->where('field', 'experience')->where('status', BrandEpicrisisItem::CONFLICT)->count());
        $pack = app(Epicrisis::class)->forPrompt($this->brand, 'content');
        $this->assertEmpty(array_filter($pack['facts'], fn (string $l): bool => str_contains($l, 'beri')), 'conflicting facts never reach AI work');
        $this->assertContains('Deneyim / kuruluş', $pack['unknown']);
        $question = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->get()->first(fn ($q): bool => data_get($q->detail, 'kind') === 'conflict');
        $this->assertNotNull($question);
        $this->assertStringContainsString('2008', $question->value);
        $this->assertStringContainsString('İşletme Profili', $question->value);

        app(Epicrisis::class)->answer($question, '2008\'den beri hizmet veriyor', $this->admin);

        $this->assertSame(BrandEpicrisisItem::ANSWERED, $question->fresh()->status);
        $live = BrandEpicrisisItem::query()->where('field', 'experience')->where('status', BrandEpicrisisItem::ACTIVE)->get();
        $this->assertCount(1, $live);
        $this->assertTrue($live->first()->locked);
        $this->assertSame(BrandEpicrisisItem::VERIFIED, $live->first()->confidence);
        $this->assertSame(BrandEpicrisisItem::DROPPED, BrandEpicrisisItem::query()->where('value', '2012\'den beri hizmet veriyor')->first()->status);
        $this->assertContains('Deneyim / kuruluş: 2008\'den beri hizmet veriyor (doğrulandı)', app(Epicrisis::class)->forPrompt($this->brand, 'content')['facts']);

        // The other source keeps saying 2012: the operator's word still wins and nothing is asked again.
        $this->record('experience', '2012\'den beri hizmet veriyor', 'gbp');
        app(Epicrisis::class)->build($this->brand);
        $this->assertSame(0, BrandEpicrisisItem::query()->where('field', 'experience')->where('status', BrandEpicrisisItem::CONFLICT)->count());
    }

    public function test_a_line_with_a_forbidden_phrase_of_the_sector_is_kept_out_of_the_writers_input(): void
    {
        $this->record('warranty', 'İmplantta 10 yıl garanti', 'operator');
        app(Epicrisis::class)->build($this->brand);
        $terms = ForbiddenTerms::forBrand($this->brand);
        $facts = app(Epicrisis::class)->forPrompt($this->brand, 'content')['facts'];

        $this->assertSame($terms->blocking('İmplantta 10 yıl garanti') === [], in_array('Garanti: İmplantta 10 yıl garanti (doğrulandı)', $facts, true));
    }

    public function test_an_old_price_expires_and_an_operator_line_past_its_date_is_asked_again(): void
    {
        $this->record('price', 'İmplant 25.000 TL', 'site');
        app(Epicrisis::class)->record($this->brand, 'package', 'Kasım kampanyası: tek seans beyazlatma', null, 'operator', $this->admin->id);
        $this->travel(100)->days();

        app(Epicrisis::class)->build($this->brand);

        $this->assertSame(BrandEpicrisisItem::EXPIRED, $this->item('price')->status);
        $pack = app(Epicrisis::class)->forPrompt($this->brand, 'ads');
        $this->assertEmpty(array_filter($pack['facts'], fn (string $l): bool => str_contains($l, '25.000') || str_contains($l, 'Kasım')));
        $asked = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('status', BrandEpicrisisItem::OPEN)->get()
            ->first(fn ($q): bool => data_get($q->detail, 'kind') === 'expired');
        $this->assertNotNull($asked);
        $this->assertStringContainsString('hâlâ geçerli mi', $asked->value);
    }

    public function test_missing_sector_fields_are_asked_and_a_dismissed_question_never_comes_back(): void
    {
        app(Epicrisis::class)->build($this->brand);
        $warranty = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('field', 'warranty')->first();
        $this->assertNotNull($warranty, 'a dental brand must know its warranty');
        $this->assertSame(BrandEpicrisisItem::OPEN, $warranty->status);
        $this->assertNull(BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('field', 'service')->first(), 'services are known');

        app(Epicrisis::class)->dismiss($warranty, $this->admin);
        app(Epicrisis::class)->build($this->brand);

        $this->assertSame(BrandEpicrisisItem::DISMISSED, $warranty->fresh()->status);
        $this->assertSame(1, BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('field', 'warranty')->count());
    }

    public function test_the_ai_site_reading_keeps_only_facts_quoted_word_for_word_with_their_numbers(): void
    {
        $this->enableAi();
        $about = $this->page('/hakkimizda/', 'Hakkımızda', ['content_text' => 'Kliniğimiz 2008 yılından beri Ankara\'da hizmet veriyor. Tüm implantlarımızda Straumann kullanıyoruz ve 10 yıl garanti veriyoruz.']);
        $sent = null;
        EpicrisisFactsAgent::fake(function (string $prompt) use (&$sent, $about): array {
            $sent = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['facts' => [
                ['field' => 'material', 'scope' => 'İmplant', 'value' => 'Straumann implant', 'source' => $about->url, 'quote' => 'Tüm implantlarımızda Straumann kullanıyoruz'],
                ['field' => 'warranty', 'scope' => 'İmplant', 'value' => '20 yıl garanti', 'source' => $about->url, 'quote' => 'implantlarımızda Straumann kullanıyoruz ve 10 yıl garanti veriyoruz'],
                ['field' => 'experience', 'scope' => null, 'value' => '2008\'den beri', 'source' => $about->url, 'quote' => '2008 yılından beri Ankara\'da hizmet veriyor'],
                ['field' => 'license', 'scope' => null, 'value' => 'Ruhsat 1234', 'source' => $about->url, 'quote' => 'Ruhsat numaramız 1234'],
                ['field' => 'phone', 'scope' => null, 'value' => '0312 000 00 00', 'source' => $about->url, 'quote' => 'Kliniğimiz 2008 yılından beri'],
            ]];
        });

        $this->assertSame(['status' => 'ready', 'facts' => 2], app(EpicrisisFacts::class)->readSite($this->brand));

        $this->assertSame(['service', 'branch', 'phone', 'email', 'address', 'hours', 'website'], EpicrisisFacts::RULE_FIELDS);
        $this->assertNotContains('phone', array_column($sent['fields'], 'field'), 'the rules read phones, not the AI');
        $this->assertSame('Straumann implant', $this->item('material')->value);
        $this->assertSame($about->url, $this->item('material')->source_ref);
        $this->assertNotNull($this->item('experience'));
        $this->assertNull($this->item('warranty'), 'a number not in its quote is invented');
        $this->assertNull($this->item('license'), 'a quote not in the page is invented');
        $this->assertSame(['status' => 'unchanged'], app(EpicrisisFacts::class)->readSite($this->brand), 'unchanged pages are not read again');
    }

    public function test_the_synthesis_keeps_only_lines_resting_on_cited_records_and_every_ai_step_reads_its_slice(): void
    {
        $this->enableAi();
        $this->record('experience', '2008\'den beri Çankaya\'da', 'operator');
        $this->record('differentiator', 'Tedaviyi aynı hekim baştan sona yapar', 'site');
        $this->store()->sync($this->brand, BrandEpicrisisItem::EVIDENCE, [['field' => 'objection', 'value' => 'Ağrı', 'source_kind' => 'reviews']], 'rules');
        $this->store()->reconcile($this->brand);
        $since = $this->item('experience');
        $doctor = $this->item('differentiator');
        $pain = $this->item('objection', BrandEpicrisisItem::EVIDENCE);
        EpicrisisSynthesisAgent::fake([['items' => [
            ['kind' => 'positioning', 'text' => 'İmplantı tek hekimle yapan, 2008\'den beri Çankaya\'daki klinik.', 'answer' => null, 'refs' => [$since->id, $doctor->id]],
            ['kind' => 'objection', 'text' => 'İmplant ağrılı mı?', 'answer' => 'Aynı hekim baştan sona yanınızda.', 'refs' => [$pain->id, $doctor->id]],
            ['kind' => 'proof', 'text' => '5000 mutlu hasta', 'answer' => null, 'refs' => [$since->id]],
            ['kind' => 'say', 'text' => 'Hekimin adını söyle.', 'answer' => null, 'refs' => [999999]],
            ['kind' => 'voice', 'text' => 'Sakin, açıklayıcı, tıbbi abartısız.', 'answer' => null, 'refs' => [$pain->id]],
        ]]]);

        $this->assertTrue(app(EpicrisisSynthesis::class)->due($this->brand));
        $result = app(EpicrisisSynthesis::class)->run($this->brand);

        $this->assertSame(['status' => 'ready', 'items' => 3, 'rejected' => 2], $result, 'a number the refs do not hold, refs that do not exist');
        $positioning = $this->item('positioning', BrandEpicrisisItem::SYNTHESIS);
        $this->assertSame(BrandEpicrisisItem::INFERRED, $positioning->confidence);
        $this->assertSame([$since->id, $doctor->id], data_get($positioning->detail, 'refs'));
        $this->assertFalse(app(EpicrisisSynthesis::class)->due($this->brand), 'nothing changed since');

        app(Epicrisis::class)->summarize($this->brand);
        $content = app(Epicrisis::class)->forPrompt($this->brand, 'content');
        $this->assertSame('İmplantı tek hekimle yapan, 2008\'den beri Çankaya\'daki klinik.', $content['positioning']);
        $this->assertContains('İtiraz ve cevabı: İmplant ağrılı mı? → Cevap: Aynı hekim baştan sona yanınızda.', $content['insights']);
        $this->assertSame($content, app(Epicrisis::class)->forPrompt($this->brand, 'content'), 'the same record gives the same AI input');
        $this->assertGreaterThan(0, $positioning->fresh()->used_count);
        $this->assertEmpty(array_filter($content['facts'], fn (string $l): bool => str_starts_with($l, 'Telefon')), 'phones are not sent to the article writer');
        $this->assertSame([], Epicrisis::input(null, 'content'));
        $this->assertArrayHasKey('brand_epicrisis', Epicrisis::input($this->brand, 'gbp'));
    }

    public function test_the_prompts_of_the_writers_and_the_checker_get_the_epikriz_rule_and_others_do_not(): void
    {
        $registry = app(PromptRegistry::class);
        $render = fn (string $op): string => $registry->renderVersion(new PromptVersion(['operation' => $op, 'template' => 'Do the work.']));

        $this->assertStringContainsString(PromptRegistry::EPICRISIS_WRITER, $render(AiRouteKeys::SITE_WRITE_ARTICLE));
        $this->assertStringContainsString(PromptRegistry::EPICRISIS_WRITER, $render(AiRouteKeys::GBP_REVIEW_REPLY));
        $this->assertStringContainsString(PromptRegistry::EPICRISIS_CHECKER, $render(AiRouteKeys::CONTENT_REVIEW));
        $this->assertSame("Do the work.\n\n".PromptRegistry::GUARD, $render(AiRouteKeys::BRAND_CANDIDATES));
    }

    public function test_the_claim_check_holds_phones_prices_warranties_and_counts_nobody_stated(): void
    {
        $this->record('phone', '0312 123 45 67', 'gbp');
        $this->record('warranty', 'İmplantta 10 yıl garanti', 'site');
        $this->store()->reconcile($this->brand);
        $claims = app(EpicrisisClaims::class);

        $this->assertSame([], $claims->unsupported($this->brand, '<p>Bizi +90 (312) 123 45 67 numarasından arayın. İmplantlarda 10 yıl garanti veriyoruz; tedavi 3 ay sürer, %95 vakada sorun olmaz.</p>'));
        $found = $claims->unsupported($this->brand, '<p>Hemen 0532 111 22 33 arayın! 15 yıl garanti, 20 yıllık deneyim, 5.000 mutlu hasta, implant 18.000 TL. info@baska.com</p>');
        $this->assertContains('telefon 0532 111 22 33', $found);
        $this->assertContains('garanti: 15 yıl garanti', $found);
        $this->assertContains('deneyim: 20 yıllık deneyim', $found);
        $this->assertContains('hasta / vaka sayısı: 5.000 mutlu hasta', $found);
        $this->assertContains('fiyat: 18.000 TL', $found);
        $this->assertContains('e-posta info@baska.com', $found);
        $this->assertSame([], $claims->unsupported($this->brand, 'Fiyat 18.000 TL.', ['Kampanya: 18.000 TL']), 'the text\'s own source may hold it');
        $this->assertStringStartsWith('Kaynağı olmayan bilgi: telefon', EpicrisisClaims::reason($found));
    }

    public function test_lessons_come_from_the_operators_rejections_and_reasons_the_check_repeats(): void
    {
        BrandMemory::query()->create(['brand_id' => $this->brand->id, 'kind' => 'decision', 'ref_type' => 'suggestion', 'ref_id' => 7,
            'data' => ['decision' => 'reddedildi', 'reason' => 'Fiyat yazmayalım', 'title' => 'İmplant fiyatları 2026', 'at' => '2026-10-10']]);
        $repeated = app(EpicrisisLessons::class)->repeated(collect(['Makale: Yazı genel kalmış, markaya özgü bilgi yok.', 'Yazı genel kalmış, şube adı geçmiyor.', 'Başka bir konu.']));
        $this->assertSame([['text' => 'Yazı genel kalmış, markaya özgü bilgi yok.', 'count' => 2]], $repeated->all());

        app(EpicrisisLessons::class)->collect($this->brand);

        $lesson = $this->item('rejected', BrandEpicrisisItem::LESSON);
        $this->assertSame('Fiyat yazmayalım (öneri: İmplant fiyatları 2026)', $lesson->value);
        $this->assertSame(BrandEpicrisisItem::VERIFIED, $lesson->confidence);
    }

    public function test_the_epikriz_tab_answers_corrects_confirms_and_sets_aside(): void
    {
        $this->record('material', 'Osstem implant', 'site');
        app(Epicrisis::class)->build($this->brand);
        $question = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('field', 'warranty')->firstOrFail();
        $material = $this->item('material');

        Livewire::test(BrandEpicrisisTab::class, ['brandId' => $this->brand->id])
            ->assertSee('Marka epikrizi')->assertSee('Sana soracaklarım')->assertSee('Osstem implant')->assertSeeHtml('data-epicrisis-item="'.$material->id.'"')
            ->set('answers.'.$question->id, 'İmplantta ömür boyu garanti')->call('answer', $question->id)
            ->assertSee('Cevap kaydedildi')
            ->call('edit', $material->id)->set('editText', 'Straumann implant')->call('saveEdit')
            ->set('newField', 'differentiator')->set('newValue', 'Aynı gün geçici diş')->call('add')
            ->assertSee('Straumann implant')->assertSee('Aynı gün geçici diş');

        $this->assertSame('İmplantta ömür boyu garanti', $this->item('warranty')->value);
        $this->assertSame(BrandEpicrisisItem::DROPPED, $material->fresh()->status);
        $this->assertTrue(BrandEpicrisisItem::query()->where('value', 'Straumann implant')->firstOrFail()->locked);

        $doctor = BrandEpicrisisItem::query()->where('value', 'Aynı gün geçici diş')->firstOrFail();
        Livewire::test(BrandEpicrisisTab::class, ['brandId' => $this->brand->id])->call('reject', $doctor->id);
        $this->assertSame(BrandEpicrisisItem::DROPPED, $doctor->fresh()->status);
        app(Epicrisis::class)->build($this->brand);
        $this->assertSame(BrandEpicrisisItem::DROPPED, $doctor->fresh()->status, 'a line the operator set aside is not brought back');

        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'dosya']))->assertOk()->assertSee('Epikriz');
    }

    public function test_the_today_box_lists_every_brands_open_questions_and_answers_them(): void
    {
        app(Epicrisis::class)->build($this->brand);
        $question = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('field', 'warranty')->firstOrFail();

        Livewire::test(EpicrisisQuestions::class)
            ->assertSee('Markalara dair sorularım')->assertSee('Panorama Ankara')->assertSee($question->value)->assertSee('dolu')
            ->set('answers.'.$question->id, '10 yıl')->call('answer', $question->id);

        $this->assertSame(BrandEpicrisisItem::ANSWERED, $question->fresh()->status);
        $this->assertSame('10 yıl', $this->item('warranty')->value);
    }

    public function test_a_fact_told_in_chat_is_written_through_mcp_as_the_operators_and_get_brand_shows_the_record(): void
    {
        app(Epicrisis::class)->build($this->brand);
        $question = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('field', 'license')->firstOrFail();

        MoxdopServer::tool(SaveBrandFact::class, ['brand_id' => $this->brand->id, 'field' => 'material', 'value' => 'Osstem; isteyene Straumann', 'scope' => 'İmplant'])
            ->assertOk()->assertSee('doğrulandı, kilitli');
        MoxdopServer::tool(SaveBrandFact::class, ['brand_id' => $this->brand->id, 'question_id' => $question->id, 'value' => 'ST-1241'])->assertOk()->assertSee('cevaplandı');
        MoxdopServer::tool(SaveBrandFact::class, ['brand_id' => $this->brand->id, 'field' => 'nope', 'value' => 'x'])->assertHasErrors();

        $material = $this->item('material');
        $this->assertSame('chat', $material->source_kind);
        $this->assertTrue($material->locked);
        $this->assertSame('ST-1241', $this->item('license')->value);
        $this->assertSame('chat', $this->item('license')->source_kind);

        $briefing = app(BrandBriefing::class)->brand($this->brand);
        $this->assertStringContainsString('Osstem; isteyene Straumann', (string) $briefing['epicrisis']['markdown']);
        $this->assertNotEmpty($briefing['epicrisis']['open_questions']);
        $this->assertNull(app(BrandBriefing::class)->brand($this->brand, [], true)['epicrisis'], 'unchanged since the last read');
    }
}
