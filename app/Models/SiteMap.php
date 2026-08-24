<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * SVG floor plan for a site. A site may have multiple maps (e.g. per floor).
 *
 * @property int                          $id
 * @property int                          $site_id
 * @property string                       $floor_name
 * @property string                       $svg_map
 * @property array<string, mixed>|null    $scene
 * @property int                          $sort_order
 * @property Carbon                       $created_at
 * @property Carbon                       $updated_at
 *
 * @property-read Site $site
 */
class SiteMap extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'floor_name',
        'svg_map',
        'scene',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'scene' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
