<?php

namespace App\Models\Concerns;

use App\Support\CatalogBehaviorClassifier;
use Illuminate\Database\Eloquent\Builder;

trait HasCatalogAttributes
{
    /**
     * URL gambar lokal katalog (dari public/storage/... atau URL/path publik).
     */
    public function getImageUrlAttribute(): ?string
    {
        $raw = trim((string) ($this->image_path ?? ''));
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        if (str_starts_with($raw, '/')) {
            return asset(ltrim($raw, '/'));
        }

        return asset('storage/' . ltrim($raw, '/'));
    }

    /**
     * Format harga dalam Rupiah.
     */
    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format((int) $this->price, 0, ',', '.');
    }

    /**
     * Label manusia untuk tipe item ('Jasa' vs 'Barang').
     */
    public function getTypeLabelAttribute(): string
    {
        return $this->item_type === 'barang' ? 'Barang' : 'Jasa';
    }

    /**
     * Label kemasan/satuan beli.
     */
    public function getPackLabelAttribute(): string
    {
        return '1 ' . ($this->unit ?: 'pcs');
    }

    /**
     * Konversi baris katalog menjadi array terstruktur untuk JSON/Alpine/API.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        return array_merge([
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'category' => (string) $this->category,
            'item_type' => (string) ($this->item_type ?: 'jasa'),
            'type_label' => $this->type_label,
            'sub_category' => $this->sub_category,
            'brand' => $this->brand,
            'pack_qty' => (int) ($this->pack_qty ?: 1),
            'unit' => (string) ($this->unit ?: 'pcs'),
            'pack_label' => $this->pack_label,
            'price' => (int) $this->price,
            'formatted_price' => $this->formatted_price,
            'description' => $this->description,
            'metadata' => is_array($this->metadata) ? $this->metadata : [],
            'stock' => $this->stock !== null ? (int) $this->stock : null,
            'image_url' => $this->image_url,
            'is_available' => (bool) $this->is_available,
        ], CatalogBehaviorClassifier::metadataForItem($this));
    }

    public function scopeSearchTrigram(Builder $query, string $keyword): Builder
    {
        $term = trim($keyword);
        if ($term === '') {
            return $query;
        }

        $like = "%{$term}%";
        $table = $query->getModel()->getTable();

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->where(function (Builder $sub) use ($table, $term, $like): void {
                $sub->whereRaw("{$table}.search_vector @@ plainto_tsquery('indonesian', unaccent(?))", [$term])
                    ->orWhereRaw("{$table}.name % ?", [$term])
                    ->orWhere("{$table}.name", 'ilike', $like)
                    ->orWhere("{$table}.brand", 'ilike', $like)
                    ->orWhere("{$table}.sub_category", 'ilike', $like)
                    ->orWhere("{$table}.category", 'ilike', $like)
                    ->orWhere("{$table}.description", 'ilike', $like);
            });
        }

        return $query->where(function (Builder $sub) use ($table, $like): void {
            $sub->whereSearchLike("{$table}.name", $like)
                ->orWhereSearchLike("{$table}.brand", $like)
                ->orWhereSearchLike("{$table}.sub_category", $like)
                ->orWhereSearchLike("{$table}.category", $like)
                ->orWhereSearchLike("{$table}.description", $like);
        });
    }
}
