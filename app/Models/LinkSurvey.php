<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Bağlantı kaynakları" formu (Backlink hizmeti, 2026-11-06): one standing link per brand (`/baglanti-kaynaklari/{token}`,
 * no login, not indexed) where the client writes its real relations: memberships, the product brands it uses, where its
 * experts studied or teach, what it sponsors, who wrote about it, its partners. Each line becomes an epikriz fact and a
 * relation row on the Bağlantı masası; the best links of an agency come from these.
 */
class LinkSurvey extends Model
{
    /** key => [question, help, epikriz field, pool kind, engine] */
    public const array QUESTIONS = [
        'memberships' => ['Üye olduğunuz dernek, oda ve meslek kuruluşları', 'Ör. İzmir Diş Hekimleri Odası, Türk Ortodonti Derneği', 'certificate', 'dernek', 'iliski'],
        'suppliers' => ['Kullandığınız ürün, cihaz ve malzeme markaları; çalıştığınız tedarikçiler', 'Ör. Straumann implant, Ivoclar zirkonyum, kumaşını aldığınız firma', 'material', 'tedarikci', 'iliski'],
        'education' => ['Uzmanlarınızın mezun olduğu ya da ders verdiği üniversiteler, konuşmacı olduğu kongreler', 'Ör. Ege Üniversitesi Diş Hekimliği Fakültesi, 2025 TDB Kongresi', 'relation', 'universite', 'iliski'],
        'sponsorship' => ['Sponsor olduğunuz ya da destek verdiğiniz etkinlik, okul, kulüp ve dernekler', 'Ör. mahalle futbol kulübü, okul sağlık günü', 'relation', 'etkinlik', 'iliski'],
        'press' => ['Hakkınızda haber, röportaj ya da yazı yayımlayan siteler (adresiyle)', 'Ör. https://www.yerelhaber.com/… röportaj', 'relation', 'yerel_haber', 'anilma'],
        'partners' => ['Anlaşmalı olduğunuz kurumlar ve iş birlikleri', 'Ör. anlaşmalı sigorta şirketi, kurum indirimi verdiğiniz firma', 'relation', 'diger', 'iliski'],
    ];

    /** @var list<string> */
    protected $fillable = ['brand_id', 'token', 'answers', 'note', 'answered_at', 'rows_created'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['answers' => 'array', 'answered_at' => 'immutable_datetime', 'rows_created' => 'integer'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
