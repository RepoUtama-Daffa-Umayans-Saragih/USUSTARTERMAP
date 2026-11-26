<?php

namespace App\Models;

use App\Models\Concerns\HasSpatialScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use HasFactory;
    use HasSpatialScopes;

    protected $fillable = [
        'name',
        'code',
        'faculty',
        'category',
        'latitude',
        'longitude',
        'entrance_lat',
        'entrance_lng',
        'description',
        'photo_360_path',
        'door_photo_path',
        'photo_360_meta',
        'floor_count',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'entrance_lat' => 'float',
            'entrance_lng' => 'float',
            'photo_360_meta' => 'array',
            'floor_count' => 'integer',
        ];
    }

    public function getPhoto360UrlAttribute(): ?string
    {
        return \App\Support\CampusMediaUrl::resolve($this->photo_360_path);
    }

    public function getDoorPhotoUrlAttribute(): ?string
    {
        return \App\Support\CampusMediaUrl::resolve($this->door_photo_path);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function scopeSearchTrigram(Builder $query, string $keyword): Builder
    {
        $term = trim($keyword);
        if ($term === '') {
            return $query;
        }

        $like = "%{$term}%";

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->where(function (Builder $sub) use ($term, $like): void {
                $sub->whereRaw('buildings.name % ?', [$term])
                    ->orWhere('buildings.name', 'ilike', $like)
                    ->orWhere('buildings.code', 'ilike', $like)
                    ->orWhere('buildings.faculty', 'ilike', $like);
            });
        }

        return $query->where(function (Builder $sub) use ($like): void {
            $sub->whereSearchLike('name', $like)
                ->orWhereSearchLike('code', $like)
                ->orWhereSearchLike('faculty', $like);
        });
    }
}
