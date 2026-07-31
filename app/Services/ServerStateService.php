<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Models\ModpackInstallation;

class ServerStateService
{
    private const RESTORE_EVENT = 'server:backup.restore-complete';

    public function currentInstallation(Server $server): ?ModpackInstallation
    {
        $last = ModpackInstallation::query()
            ->where('server_id', $server->id)
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return null;
        }

        if (in_array($last->status, ModpackInstallation::ACTIVE_STATUSES, true)) {
            return $last;
        }

        $restoredBackupId = $this->lastRestoredBackupId($server, $last->updated_at);
        if ($restoredBackupId === null) {
            return $last;
        }

        $backupUuid = Backup::query()->withTrashed()->whereKey($restoredBackupId)->value('uuid');

        $ours = $backupUuid === null ? null : ModpackInstallation::query()
            ->where('server_id', $server->id)
            ->where('backup_ref', $backupUuid)
            ->first();

        if (!$ours) {
            return null;
        }

        return ModpackInstallation::query()
            ->where('server_id', $server->id)
            ->where('id', '<', $ours->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();
    }

    private function lastRestoredBackupId(Server $server, $after): ?int
    {
        $id = DB::table('activity_logs as l')
            ->join('activity_log_subjects as s', function ($j) use ($server) {
                $j->on('s.activity_log_id', '=', 'l.id')
                    ->where('s.subject_type', (new Server())->getMorphClass())
                    ->where('s.subject_id', $server->id);
            })
            ->join('activity_log_subjects as b', function ($j) {
                $j->on('b.activity_log_id', '=', 'l.id')
                    ->where('b.subject_type', (new Backup())->getMorphClass());
            })
            ->where('l.event', self::RESTORE_EVENT)
            ->where('l.timestamp', '>', $after)
            ->orderByDesc('l.id')
            ->value('b.subject_id');

        return $id === null ? null : (int) $id;
    }
}
