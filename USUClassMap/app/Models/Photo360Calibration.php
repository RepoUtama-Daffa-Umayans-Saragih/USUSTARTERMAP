<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo360Calibration extends Model
{
    protected $table = 'photo_360_calibrations';

    protected $fillable = [
        'filename',
        'room_id',
        'building_id',
        'pitch',
        'yaw',
        'hfov',
        'metadata',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'pitch' => 'float',
            'yaw' => 'float',
            'hfov' => 'float',
            'metadata' => 'array',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
