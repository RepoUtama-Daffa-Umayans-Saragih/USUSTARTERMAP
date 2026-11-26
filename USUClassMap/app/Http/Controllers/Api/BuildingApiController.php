<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Services\Cache\CampusRedisService;
use App\Services\OperationalStatusService;
use App\Support\CampusMediaUrl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BuildingApiController extends Controller
{
    public function __construct(
        protected ?OperationalStatusService $operationalStatus = null
    ) {
        $this->operationalStatus = $operationalStatus ?? app(OperationalStatusService::class);
    }

    /**
     * Get all campus buildings with GPS coordinates, rooms, and contextual schedules.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $nowWib = Carbon::now(OperationalStatusService::TIMEZONE);

        $cachedBuildings = Cache::remember(CampusRedisService::KEY_BUILDINGS_ALL_WIB, 300, function (): array {
            $mediaSummaries = DB::getDriverName() === 'pgsql'
                ? DB::table('v_building_room_media_summary')->get()->keyBy('building_id')
                : collect();

            $buildings = Building::with([
                'rooms' => function ($query) {
                    $query->orderBy('floor')->orderBy('name');
                },
                'rooms.schedules.course',
                'rooms.schedules.lecturer',
            ])->orderBy('name')->get();

            return $buildings->map(function (Building $b) use ($mediaSummaries): array {
                $summary = $mediaSummaries->get($b->id);

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'code' => $b->code,
                    'faculty' => $b->faculty,
                    'category' => $b->category,
                    'latitude' => (float) $b->latitude,
                    'longitude' => (float) $b->longitude,
                    'description' => $b->description,
                    'photo_360_path' => $b->photo_360_path,
                    'door_photo_path' => $b->door_photo_path,
                    'photo_360_meta' => $b->photo_360_meta,
                    'floor_count' => (int) $b->floor_count,
                    'rooms_count' => $summary !== null ? (int) $summary->rooms_count : $b->rooms->count(),
                    'rooms' => $b->rooms->map(function ($r): array {
                        return [
                            'id' => $r->id,
                            'name' => $r->name,
                            'floor' => (int) $r->floor,
                            'capacity' => (int) $r->capacity,
                            'photo_360_path' => $r->photo_360_path,
                            'door_photo_path' => $r->door_photo_path,
                            'photo_360_meta' => $r->photo_360_meta,
                            'schedules' => $r->schedules->map(function ($s): array {
                                return [
                                    'id' => $s->id,
                                    'mata_kuliah' => [
                                        'id' => $s->course->id ?? null,
                                        'kode' => $s->course->code ?? '-',
                                        'nama' => $s->course->name ?? '-',
                                        'sks' => $s->course->credits ?? 0,
                                    ],
                                    'dosen' => [
                                        'id' => $s->lecturer->id ?? null,
                                        'nama' => $s->lecturer->name ?? '-',
                                    ],
                                    'hari' => $s->day,
                                    'jam_mulai' => $s->time_start ? substr($s->time_start, 0, 5) : '--:--',
                                    'jam_selesai' => $s->time_end ? substr($s->time_end, 0, 5) : '--:--',
                                    'semester' => $s->semester,
                                    'kelas' => $s->class_group,
                                    'kelompok_kelas' => $s->class_group,
                                ];
                            })->values()->all(),
                        ];
                    })->values()->all(),
                ];
            })->all();
        });

        $data = array_map(function (array $b) use ($user, $nowWib): array {
            $opStatus = $this->operationalStatus->evaluateBuildingStatus($b, $nowWib);

            $photo360Path = $b['photo_360_path'] ?? null;
            $doorPhotoPath = $b['door_photo_path'] ?? null;
            unset($b['photo_360_path'], $b['door_photo_path']);

            $rooms = array_map(function (array $r) use ($user): array {
                $rPhoto360Path = $r['photo_360_path'] ?? null;
                $rDoorPhotoPath = $r['door_photo_path'] ?? null;
                unset($r['photo_360_path'], $r['door_photo_path']);

                $schedules = array_map(function (array $s) use ($user): array {
                    $isMyClass = false;
                    if ($user && !empty($user->semester) && ($s['semester'] ?? null) === $user->semester) {
                        $isMyClass = true;
                    }

                    return array_merge($s, ['is_my_class' => (bool) $isMyClass]);
                }, $r['schedules']);

                $r['photo_360'] = isset($r['photo_360']) && $rPhoto360Path === null
                    ? $r['photo_360']
                    : CampusMediaUrl::resolve($rPhoto360Path);
                $r['door_photo'] = isset($r['door_photo']) && $rDoorPhotoPath === null
                    ? $r['door_photo']
                    : CampusMediaUrl::resolve($rDoorPhotoPath);
                $r['schedules'] = $schedules;

                return $r;
            }, $b['rooms']);

            return array_merge($b, [
                'photo_360' => isset($b['photo_360']) && $photo360Path === null
                    ? $b['photo_360']
                    : CampusMediaUrl::resolve($photo360Path),
                'door_photo' => isset($b['door_photo']) && $doorPhotoPath === null
                    ? $b['door_photo']
                    : CampusMediaUrl::resolve($doorPhotoPath),
                'operational_status' => $opStatus,
                'rooms' => $rooms,
            ]);
        }, $cachedBuildings);

        return response()->json([
            'status' => 'success',
            'evaluated_at_wib' => $nowWib->format('H:i'),
            'count' => count($data),
            'data' => $data,
        ]);
    }

    /**
     * Get specific building details.
     *
     * @param Building $building
     * @return JsonResponse
     */
    public function show(Building $building): JsonResponse
    {
        $building->load(['rooms.schedules.course', 'rooms.schedules.lecturer']);
        $opStatus = $this->operationalStatus->evaluateBuildingStatus($building);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $building->id,
                'name' => $building->name,
                'code' => $building->code,
                'latitude' => (float) $building->latitude,
                'longitude' => (float) $building->longitude,
                'description' => $building->description,
                'photo_360' => $building->photo_360_url,
                'door_photo' => $building->door_photo_url,
                'photo_360_meta' => $building->photo_360_meta,
                'floor_count' => (int) $building->floor_count,
                'operational_status' => $opStatus,
                'rooms' => $building->rooms->map(fn ($r) => array_merge($r->toArray(), [
                    'photo_360' => $r->photo_360_url,
                    'door_photo' => $r->door_photo_url,
                ]))->values(),
            ],
        ]);
    }
}
