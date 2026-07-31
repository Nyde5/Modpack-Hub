<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

class ModpackInstallation extends Model
{
    protected $table = 'modpackhub_installations';

    protected $guarded = ['id'];

    protected $casts = [
        'server_id' => 'int',
        'egg_snapshot' => 'array',
    ];

    public const ACTIVE_STATUSES = [
        'pending', 'backing_up', 'switching_egg', 'installing', 'restoring_egg',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
