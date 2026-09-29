<?php

namespace App\Support\Console;

use App\Models\Brand;
use App\Models\DigitalAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * --brand / --asset / --site / --website options of the moxdop:* commands: an id OR a (partial, case-insensitive)
 * name the way moxdop:diagnose reads it (`--brand=Panorama`). One match is used; none or several stop the command
 * with a Turkish message that lists the candidates, never with "No query results for model … 0".
 */
final class ConsoleScope
{
    private const int LISTED = 10;

    /** @throws ConsoleScopeException */
    public static function brand(string $value): Brand
    {
        $value = trim($value);
        if ($value === '') {
            throw new ConsoleScopeException('--brand boş olamaz: marka id veya adının bir parçası verin (ör. --brand=Panorama).');
        }
        if (ctype_digit($value)) {
            return Brand::query()->find((int) $value)
                ?? throw new ConsoleScopeException('Marka bulunamadı: #'.$value.'. '.self::hint(Brand::query()->orderBy('name')->limit(self::LISTED)->get(['id', 'name'])));
        }
        $matches = self::like(Brand::query(), ['name'], $value)->orderBy('name')->limit(50)->get(['id', 'name']);

        return Brand::query()->findOrFail(self::one($matches, $value, 'Marka', '--brand', fn (Brand $brand): string => (string) $brand->name)->id);
    }

    /**
     * A digital asset by id or by (partial) name / domain / address; $type narrows it (e.g. "website").
     *
     * @throws ConsoleScopeException
     */
    public static function asset(string $value, ?string $type = null, string $option = '--asset'): DigitalAsset
    {
        $value = trim($value);
        if ($value === '') {
            throw new ConsoleScopeException($option.' boş olamaz: varlık id veya adının / alan adının bir parçası verin.');
        }
        $base = fn (): Builder => DigitalAsset::query()->when($type !== null, fn (Builder $q) => $q->where('type', $type));
        if (ctype_digit($value)) {
            return $base()->find((int) $value)
                ?? throw new ConsoleScopeException(($type === 'website' ? 'Web sitesi' : 'Varlık').' bulunamadı: #'.$value.'.');
        }
        $matches = self::like($base(), ['name', 'domain', 'primary_url'], $value)->orderBy('name')->limit(50)->get(['id', 'name', 'domain', 'type']);

        $match = self::one($matches, $value, $type === 'website' ? 'Web sitesi' : 'Varlık', $option,
            fn (DigitalAsset $asset): string => trim((string) $asset->name.($asset->domain ? ' ('.$asset->domain.')' : '')));

        return DigitalAsset::query()->findOrFail($match->id);
    }

    /**
     * Websites of a brand given as id or name (moxdop:pilot:refresh, --brand on website commands).
     *
     * @return Collection<int, DigitalAsset>
     */
    public static function websitesOf(Brand $brand): Collection
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private static function like(Builder $query, array $columns, string $value): Builder
    {
        // mb_strtolower + LOWER(): "panorama" finds "Panorama Ankara" on PostgreSQL and SQLite alike.
        $needle = '%'.mb_strtolower($value).'%';

        return $query->where(function (Builder $scope) use ($columns, $needle): void {
            foreach ($columns as $column) {
                $scope->orWhereRaw('LOWER('.$column.') LIKE ?', [$needle]);
            }
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $matches
     * @param  callable(TModel): string  $label
     * @return TModel
     */
    private static function one(Collection $matches, string $value, string $noun, string $option, callable $label): mixed
    {
        if ($matches->count() === 1) {
            return $matches->first();
        }
        // Several partial matches but one exact name: that one ("Panorama" vs "Panorama Ankara").
        $exact = $matches->filter(fn ($model): bool => mb_strtolower(trim((string) $model->name)) === mb_strtolower($value));
        if ($exact->count() === 1) {
            return $exact->first();
        }
        if ($matches->isEmpty()) {
            throw new ConsoleScopeException($noun.' bulunamadı: "'.$value.'". '.$option.' için id veya adın bir parçasını verin.');
        }
        $listed = $matches->take(self::LISTED)->map(fn ($model): string => '#'.$model->id.' '.$label($model))->implode(', ');

        throw new ConsoleScopeException(sprintf('"%s" birden fazla %s ile eşleşti (%d): %s%s. %s=<id> ile birini seçin.',
            $value, mb_strtolower($noun), $matches->count(), $listed, $matches->count() > self::LISTED ? ', …' : '', $option));
    }

    /** @param  Collection<int, Brand>  $brands */
    private static function hint(Collection $brands): string
    {
        return $brands->isEmpty() ? '' : 'Markalar: '.$brands->map(fn (Brand $b): string => '#'.$b->id.' '.$b->name)->implode(', ').'.';
    }
}
