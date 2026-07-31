<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Pterodactyl\Models\Server;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Models\ModpackInstallation;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackInstallException;

class InstallOrchestrator
{
    public function __construct(
        private EggSwitchService $egg,
        private WingsFileService $files,
        private CurseForgeManifestService $cfManifest,
    ) {}

    public function start(Server $server, array $resolved, int $installationId): void
    {
        $inst = ModpackInstallation::findOrFail($installationId);
        $snapshot = null;
        $ok = false;

        try {
            $modList = null;

            if (($resolved['format'] ?? null) === CurseForgeService::LAYOUT_MANIFEST) {
                $modList = $this->cfManifest->fileList($resolved['pack_url'], $resolved['size'] ?? null);

                if ($modList === null) {
                    $resolved['format'] = CurseForgeService::LAYOUT_SERVERPACK;
                }

                if ($modList !== null && $modList['unavailable'] !== []) {
                    if (empty($resolved['allow_missing'])) {
                        throw new ModpackInstallException($this->missingMessage($modList['unavailable']));
                    }

                    $inst->update(['notes' => $this->missingNote($modList['unavailable'])]);
                }
            }

            $sizeToCheck = $modList !== null ? $modList['bytes'] : (int) ($resolved['size'] ?? 0);

            if ($sizeToCheck > 0 && !$this->files->hasDiskSpace($server, $sizeToCheck)) {
                throw new ModpackInstallException($modList !== null
                    ? sprintf(
                        'The %d mods of this pack (%d MB) do not fit in the server disk limit: free some space or '
                        . 'ask an administrator to raise it.',
                        $modList['count'],
                        intdiv($sizeToCheck, 1024 * 1024)
                    )
                    : sprintf(
                        'This pack (%d MB) does not fit in the server disk limit: free some space or ask an '
                        . 'administrator to raise it.',
                        intdiv($sizeToCheck, 1024 * 1024)
                    ));
            }

            $inst->update(['status' => 'backing_up']);
            if (($resolved['backup'] ?? true) && $this->files->backupsAllowed($server)) {
                $ref = $this->files->backup($server, $this->currentStateLabel($inst));
                $inst->update(['backup_ref' => $ref]);
            }

            $snapshot = $this->egg->snapshot($server);
            $inst->update(['egg_snapshot' => $snapshot, 'status' => 'switching_egg']);

            if ($modList !== null) {
                $this->files->putInstallFiles($server, $modList['content']);
            }

            $this->egg->switchToInstaller($server, $this->eggVars($resolved));
            $inst->update(['status' => 'installing']);

            if (!$this->egg->waitForInstall($server)) {
                throw new ModpackInstallException('The installation script on the node failed or timed out.');
            }

            $outcome = $this->files->readInstallResult($server);

            if ($outcome === null) {
                throw new ModpackInstallException(
                    'The install script did not report its result: it did not run to completion. '
                    . 'The server files may be unchanged or only partially updated.'
                );
            }
            if ($outcome !== 'ok') {
                throw new ModpackInstallException(
                    'The install script failed on the node: ' . preg_replace('/^fail:\s*/', '', $outcome)
                );
            }

            $ok = true;
        } catch (\Throwable $e) {
            $inst->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        } finally {
            if ($snapshot !== null) {
                if ($ok) {
                    $inst->update(['status' => 'restoring_egg']);
                }
                try {
                    $this->egg->restore($server->refresh(), $snapshot);
                } catch (\Throwable $e) {
                    $ok = false;
                    $inst->update([
                        'status' => 'failed',
                        'error_message' => trim(($inst->error_message ?? '') . ' [EGG RESTORE FAILED: ' . $e->getMessage()
                            . ' — the server may still be on the installer egg, admin intervention needed]'),
                    ]);
                }
                $this->files->cleanupTemp($server);
            }

            if ($ok) {
                $inst->update(['status' => 'completed', 'error_message' => null]);
            }
        }
    }

    private function currentStateLabel(ModpackInstallation $inst): string
    {
        $current = ModpackInstallation::query()
            ->where('server_id', $inst->server_id)
            ->where('id', '<', $inst->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->value('pack_name');

        return $current ?: 'state before ' . $inst->pack_name;
    }

    private function missingMessage(array $missing): string
    {
        return sprintf(
            '%d mod(s) of this pack cannot be downloaded automatically because their authors did not allow '
            . 'redistribution: %s. Install it without them from the install dialog, or download the pack '
            . 'manually and use the "Direct URL" source.',
            count($missing),
            $this->shortList($missing)
        );
    }

    private function missingNote(array $missing): string
    {
        return sprintf(
            'Installed without %d mod(s) whose authors do not allow automatic download: %s. '
            . 'Add them by hand to the mods folder if you need them.',
            count($missing),
            $this->shortList($missing)
        );
    }

    private function shortList(array $names): string
    {
        $shown = array_slice($names, 0, 5);

        return implode(', ', $shown) . (count($names) > count($shown) ? ', …' : '');
    }

    private function eggVars(array $resolved): array
    {
        return [
            'PACK_URL' => $resolved['pack_url'],
            'PACK_FORMAT' => $resolved['format'] ?? 'zip',
            'MC_VERSION' => $resolved['mc_version'] ?? '',
            'LOADER' => $resolved['loader'] ?? 'none',
            'LOADER_VERSION' => $resolved['loader_version'] ?? '',
            'INSTALL_LOADER' => !empty($resolved['install_loader']) ? '1' : '0',
            'ACCEPT_EULA' => !empty($resolved['accept_eula']) ? '1' : '0',

            'CLEAN_MODS' => ($resolved['replace_mods'] ?? true) ? '1' : '0',
        ];
    }
}
