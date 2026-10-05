<?php

namespace Tests\Feature\IntelligenceCore;

use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Enums\IntelligenceCore\SearchTermKind;
use App\Models\Brand;
use App\Models\IntelligenceCore\IntelligenceSearchTermAlias;
use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use App\Services\IntelligenceCore\Identity\SearchTermIdentityResolver;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * resolveMany() stores what resolve() once per term, in order, stored: a term seen again widens first / last seen,
 * the last observation of an alias fills it, an empty term fails after the terms before it.
 */
final class SearchTermIdentityBatchResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_batch_widens_seen_times_and_fills_aliases_like_one_resolve_per_term(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
        $brand = Brand::factory()->create();
        $resolver = app(SearchTermIdentityResolver::class);
        $existing = $resolver->resolve($brand, 'tedavi', SearchTermKind::GscQuery, $this->source('tedavi', 1), $this->time('2026-01-02 00:00:00'));
        $resolver->resolve($brand, 'tedavi', SearchTermKind::GscQuery, $this->source('tedavi', 1), $this->time('2026-01-01 00:00:00'));

        $ids = $resolver->resolveMany($brand, [
            $this->observation('Diş İmplant', $this->source('Diş İmplant'), '2026-09-10 10:00:00'),
            $this->observation('diş implant', $this->source('diş implant'), '2026-09-08 08:00:00'),
            $this->observation('tedavi', $this->source('tedavi', 2), '2026-09-12 06:00:00'),
            $this->observation('diş implant ', $this->source('diş implant'), '2026-09-12 12:00:00'),
        ]);

        $implant = IntelligenceSearchTermIdentity::query()->where('canonical_text', 'diş implant')->sole();
        $this->assertSame([$implant->id, $implant->id, $existing->id, $implant->id], $ids);
        $this->assertSame('2026-09-08 08:00:00', $implant->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 12:00:00', $implant->last_seen_at->format('Y-m-d H:i:s'));

        $tedavi = $existing->refresh();
        $this->assertSame('2026-01-01 00:00:00', $tedavi->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 06:00:00', $tedavi->last_seen_at->format('Y-m-d H:i:s'));

        $aliases = IntelligenceSearchTermAlias::query()->where('search_term_identity_id', $implant->id)->orderBy('id')->get();
        $this->assertSame(['Diş İmplant', 'diş implant '], $aliases->pluck('observed_text')->all(), 'one alias per source, filled by its last observation');
        $this->assertSame('2026-09-08 08:00:00', $aliases[1]->first_observed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 12:00:00', $aliases[1]->last_observed_at->format('Y-m-d H:i:s'));
        $tedaviAlias = IntelligenceSearchTermAlias::query()->where('search_term_identity_id', $tedavi->id)->sole();
        $this->assertSame(2, $tedaviAlias->metadata['source_contract_version'], 'the existing alias is refilled from the batch');
        $this->assertSame('sc-domain:batch.test', $tedaviAlias->metadata['site_url']);
        $this->assertSame('2026-09-12 06:00:00', $tedaviAlias->last_observed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 00:00:00', $tedaviAlias->first_observed_at->format('Y-m-d H:i:s'));
    }

    public function test_an_empty_term_fails_after_the_terms_before_it_are_resolved(): void
    {
        $brand = Brand::factory()->create();

        try {
            app(SearchTermIdentityResolver::class)->resolveMany($brand, [
                $this->observation('önce', $this->source('önce'), '2026-09-10 10:00:00'),
                $this->observation("\u{00A0}", $this->source('nbsp'), '2026-09-10 10:00:00'),
                $this->observation('sonra', $this->source('sonra'), '2026-09-10 10:00:00'),
            ]);
            $this->fail('An empty term must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Search-term identity cannot be empty.', $exception->getMessage());
        }

        $this->assertSame(['önce'], IntelligenceSearchTermIdentity::query()->where('brand_id', $brand->id)->pluck('canonical_text')->all());
        $this->assertSame(1, IntelligenceSearchTermAlias::query()->count());
    }

    public function test_terms_beyond_one_batch_resolve_to_the_identity_of_the_earlier_batch(): void
    {
        $brand = Brand::factory()->create();
        $observations = [];
        foreach (range(0, 499) as $i) {
            $observations[] = $this->observation('terim '.$i, $this->source('terim '.$i), '2026-09-10 10:00:00');
        }
        $observations[] = $this->observation('TERİM 0', $this->source('TERİM 0'), '2026-09-14 10:00:00');

        $ids = app(SearchTermIdentityResolver::class)->resolveMany($brand, $observations);

        $this->assertCount(501, $ids);
        $this->assertSame($ids[0], $ids[500]);
        $this->assertSame(500, IntelligenceSearchTermIdentity::query()->where('brand_id', $brand->id)->count());
        $this->assertSame(501, IntelligenceSearchTermAlias::query()->count());
        $this->assertSame('2026-09-14 10:00:00', IntelligenceSearchTermIdentity::query()->findOrFail($ids[0])->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_an_unchanged_batch_only_reads(): void
    {
        $brand = Brand::factory()->create();
        $observations = array_map(
            fn (int $i): array => $this->observation('sorgu '.$i, $this->source('sorgu '.$i), '2026-09-10 10:00:00'),
            range(1, 40),
        );
        $resolver = app(SearchTermIdentityResolver::class);
        $first = $resolver->resolveMany($brand, $observations);

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower(strtok($query->sql, ' '));
        });
        $again = $resolver->resolveMany($brand, $observations);
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertSame($first, $again);
        $this->assertSame(['select', 'select'], $statements, 'one read of identities and one of aliases, nothing written');
    }

    public function test_a_term_another_worker_created_first_is_reused_and_widened(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
        $brand = Brand::factory()->create();
        $resolver = app(SearchTermIdentityResolver::class);

        // Another worker commits the same term between this batch's read of identities and its insert.
        $concurrent = null;
        DB::listen(function (QueryExecuted $query) use (&$concurrent, $resolver, $brand): void {
            if ($concurrent !== null || ! str_contains($query->sql, 'intelligence_search_term_identities') || ! str_starts_with(strtolower($query->sql), 'select')) {
                return;
            }
            $concurrent = false;
            $concurrent = $resolver->resolve($brand, 'tedavi', SearchTermKind::GscQuery, $this->source('diğer site'), $this->time('2026-09-11 00:00:00'));
        });
        $ids = $resolver->resolveMany($brand, [
            $this->observation('Tedavi', $this->source('tedavi'), '2026-09-14 00:00:00'),
            $this->observation('tedavi', $this->source('tedavi'), '2026-09-09 00:00:00'),
        ]);
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $identity = IntelligenceSearchTermIdentity::query()->where('brand_id', $brand->id)->sole();
        $this->assertSame((int) $concurrent->id, (int) $identity->id);
        $this->assertSame([(int) $identity->id, (int) $identity->id], $ids);
        $this->assertSame((string) $concurrent->uuid, (string) $identity->uuid, 'the row the other worker inserted is kept');
        $this->assertSame('2026-09-09 00:00:00', $identity->first_seen_at->format('Y-m-d H:i:s'), 'every observation of this batch widens it');
        $this->assertSame('2026-09-14 00:00:00', $identity->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, IntelligenceSearchTermAlias::query()->where('search_term_identity_id', $identity->id)->count());
    }

    public function test_changed_identities_and_aliases_are_written_in_id_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
        $brand = Brand::factory()->create();
        $resolver = app(SearchTermIdentityResolver::class);
        $texts = ['implant', 'kanal tedavisi', 'diş beyazlatma', 'ortodonti', 'diş teli', 'zirkonyum kaplama'];
        $resolver->resolveMany($brand, array_map(fn (string $text): array => $this->observation($text, $this->source($text), '2026-09-10 10:00:00'), $texts));

        // A later batch meets the same terms in another order (another site of the Brand, a new day of data). Shared
        // rows must be locked in one order (ascending id), or two such batches running at once can deadlock.
        $written = [];
        DB::listen(function (QueryExecuted $query) use (&$written): void {
            if (preg_match('/^insert into "(intelligence_search_term_[a-z]+)" \((.+?)\) values .* on conflict/is', $query->sql, $match) !== 1) {
                return;
            }
            $columns = array_map(static fn (string $column): string => trim($column, ' "'), explode(',', $match[2]));
            $idIndex = array_search('id', $columns, true);
            foreach (array_chunk($query->bindings, count($columns)) as $row) {
                $written[$match[1]][] = (int) $row[$idIndex];
            }
        });
        $resolver->resolveMany($brand, array_map(fn (string $text): array => $this->observation($text, $this->source($text), '2026-09-12 10:00:00'), array_reverse($texts)));
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertCount(6, $written['intelligence_search_term_identities'] ?? []);
        $this->assertCount(6, $written['intelligence_search_term_aliases'] ?? []);
        foreach ($written as $table => $ids) {
            $sorted = $ids;
            sort($sorted);
            $this->assertSame($sorted, $ids, $table.' rows are upserted in ascending id order');
        }
    }

    /** @return array{observed_text:string, term_kind:SearchTermKind, source:IntelligenceSourceReference, time:IntelligenceTimeContext, metadata:array<string,string>} */
    private function observation(string $text, IntelligenceSourceReference $source, string $observedAt): array
    {
        return [
            'observed_text' => $text,
            'term_kind' => SearchTermKind::GscQuery,
            'source' => $source,
            'time' => $this->time($observedAt),
            'metadata' => ['site_url' => 'sc-domain:batch.test'],
        ];
    }

    private function source(string $recordKey, ?int $contractVersion = null): IntelligenceSourceReference
    {
        return new IntelligenceSourceReference(
            providerOrSource: 'gsc', sourceClass: IntelligenceSourceClass::FirstPartyMeasured, sourceSemantic: 'query_daily',
            datasetId: 'gsc_query_daily', sourceRecordKey: 'gsc_query_daily|'.$recordKey, contractVersion: $contractVersion,
        );
    }

    private function time(string $observedAt): IntelligenceTimeContext
    {
        return new IntelligenceTimeContext(sourceTimezone: 'UTC', observedAt: CarbonImmutable::parse($observedAt, 'UTC'), languageCode: 'tr');
    }
}
