<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Pterodactyl\Models\Server;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Jobs\InstallModpackJob;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Models\ModpackInstallation;

class InstallCleanupService
{
    public const STALE_AFTER_SECONDS = InstallModpackJob::TIMEOUT;

    public function __construct(private EggSwitchService $egg) {}

    public function sweep(?int $staleAfterSeconds = null): array
    {
        $threshold = $staleAfterSeconds ?? self::STALE_AFTER_SECONDS;
        $cutoff = now()->subSeconds($threshold);

        $rows = ModpackInstallation::query()
            ->active()
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $out = ['checked' => $rows->count(), 'restored' => 0, 'failed' => 0, 'skipped_recent' => 0, 'errors' => []];

        foreach ($rows as $inst) {
            $message = $this->abortedMessage($threshold, !empty($inst->egg_snapshot));

            if (!empty($inst->egg_snapshot)) {
                $server = Server::query()->find($inst->server_id);

                if ($server === null) {
                    $message .= ' The server no longer exists.';
                } else {
                    try {
                        $this->egg->restore($server, $inst->egg_snapshot);
                        ++$out['restored'];
                    } catch (\Throwable $e) {
                        $out['errors'][] = "installation {$inst->id}: " . $e->getMessage();
                        $message .= ' [EGG RESTORE FAILED: ' . $e->getMessage()
                            . ' — the server may still be on the installer egg, admin intervention needed]';
                    }
                }
            }

            $inst->update([
                'status' => 'failed',

                'error_message' => $message,
            ]);
            ++$out['failed'];
        }

        return $out;
    }

    private function abortedMessage(int $threshold, bool $hadSnapshot): string
    {
        $hours = max(1, (int) round($threshold / 3600));

        return $hadSnapshot
            ? sprintf(
                'Installation aborted: the panel or the queue worker stopped while it was running '
                . '(no progress for more than %d hour(s)). The original egg has been restored.',
                $hours
            )
            : sprintf(
                'Installation aborted: the panel or the queue worker stopped before the server was touched '
                . '(no progress for more than %d hour(s)). Nothing was changed on the server.',
                $hours
            );
    }
}
