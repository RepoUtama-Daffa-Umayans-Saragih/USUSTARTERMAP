<?php

namespace App\Models\Mongo;

use MongoDB\Laravel\Eloquent\Model;

class Photo360pScan extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'photo_360p_scans';

    protected $fillable = [
        'photo_id',
        'filename',
        'building_code',
        'ocr_text',
        'detected_rooms',
        'verified',
        'rename_target',
        'metadata',
        'verification_history',
    ];

    protected function casts(): array
    {
        return [
            'photo_id' => 'integer',
            'verified' => 'boolean',
        ];
    }

    /**
     * Convert normalized item array from Photo360pScanRepository into MongoDB document attributes.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $metadataContext
     * @return array<string, mixed>
     */
    public static function fromScanItem(array $item, array $metadataContext = []): array
    {
        $photoId = (int) ($item['id'] ?? $item['photo_id'] ?? 0);
        $detectedRooms = is_array($item['detected_rooms'] ?? null) ? array_values($item['detected_rooms']) : [];
        $readableTexts = is_array($item['all_readable_texts'] ?? null)
            ? array_values(array_map('strval', $item['all_readable_texts']))
            : [];

        $buildingCode = $item['building_code'] ?? null;
        if (!$buildingCode && !empty($detectedRooms)) {
            $firstRoom = $detectedRooms[0]['room_name'] ?? '';
            if (preg_match('/^([A-Z]{1,6})[\s\-]/i', (string) $firstRoom, $m)) {
                $buildingCode = strtoupper($m[1]);
            }
        }

        $ocrTextParts = $readableTexts;
        foreach ($detectedRooms as $r) {
            foreach (['room_name', 'raw_text', 'verified_read', 'evidence_note'] as $field) {
                if (!empty($r[$field])) {
                    $ocrTextParts[] = (string) $r[$field];
                }
            }
        }
        if (!empty($item['scene_summary'])) {
            $ocrTextParts[] = (string) $item['scene_summary'];
        }

        $metadata = array_filter([
            'original_filename' => (string) ($item['original_filename'] ?? $item['filename'] ?? ''),
            'image_url' => (string) ($item['image_url'] ?? ''),
            'width' => (int) ($item['width'] ?? 0),
            'height' => (int) ($item['height'] ?? 0),
            'size_kb' => (float) ($item['size_kb'] ?? 0.0),
            'projection' => $item['projection'] ?? null,
            'status' => (string) ($item['status'] ?? 'pending'),
            'all_readable_texts' => $readableTexts,
            'scene_summary' => (string) ($item['scene_summary'] ?? ''),
            'admin_note' => (string) ($item['admin_note'] ?? ''),
            'scan_engine' => $metadataContext['scan_engine'] ?? ($item['scan_engine'] ?? null),
            'generated_at' => $metadataContext['generated_at'] ?? ($item['generated_at'] ?? null),
        ], static fn ($v): bool => $v !== null);

        return [
            'photo_id' => $photoId,
            'filename' => (string) ($item['filename'] ?? "{$photoId}.jpg"),
            'building_code' => $buildingCode,
            'ocr_text' => implode(' | ', array_values(array_unique(array_filter($ocrTextParts)))),
            'detected_rooms' => $detectedRooms,
            'verified' => (bool) ($item['verified_by_admin'] ?? $item['verified'] ?? false),
            'rename_target' => $item['rename_target'] ?? ($item['original_filename'] ?? null),
            'metadata' => $metadata,
        ];
    }

    /**
     * Reconstruct item payload compatible with Photo360pScanRepository.
     *
     * @return array<string, mixed>
     */
    public function toScanItem(): array
    {
        $meta = is_array($this->metadata ?? null) ? $this->metadata : [];

        return [
            'id' => (int) $this->photo_id,
            'filename' => (string) $this->filename,
            'original_filename' => (string) ($meta['original_filename'] ?? $this->rename_target ?? $this->filename),
            'image_url' => (string) ($meta['image_url'] ?? ''),
            'width' => (int) ($meta['width'] ?? 0),
            'height' => (int) ($meta['height'] ?? 0),
            'size_kb' => (float) ($meta['size_kb'] ?? 0.0),
            'projection' => $meta['projection'] ?? null,
            'status' => (string) ($meta['status'] ?? (!empty($this->detected_rooms) ? 'detected' : 'no_text')),
            'detected_rooms' => is_array($this->detected_rooms ?? null) ? $this->detected_rooms : [],
            'all_readable_texts' => is_array($meta['all_readable_texts'] ?? null) ? $meta['all_readable_texts'] : [],
            'scene_summary' => (string) ($meta['scene_summary'] ?? ''),
            'verified_by_admin' => (bool) ($this->verified ?? false),
            'admin_note' => (string) ($meta['admin_note'] ?? ''),
        ];
    }
}
