<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Models\ModpackInstallation;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackInstallException;

class WingsFileService
{
    public const TEMP_DIR = '.modpackhub-tmp';

    public const RESULT_FILE = '.modpackhub-result';

    public const FILES_LIST = '.modpackhub-files';

    public const BACKUP_PREFIX = 'ModpackHub pre-install:';

    private const POLL_SECONDS = 3;
    private const BACKUP_TIMEOUT = 900;
    private const DISK_MARGIN_BYTES = 512 * 1024 * 1024;

    public function __construct(
        private InitiateBackupService $initiateBackup,
        private DeleteBackupService $deleteBackup,
        private DaemonServerRepository $daemonServers,
        private DaemonFileRepository $daemonFiles,
    ) {}

    public function backupsAllowed(Server $server): bool
    {
        return (int) $server->backup_limit > 0;
    }

    public function backup(Server $server, string $label): string
    {
        if (!$this->backupsAllowed($server)) {
            throw new ModpackInstallException(
                'This server has a backup limit of 0: the pre-install backup cannot be created. '
                . 'Ask an administrator to raise it.'
            );
        }

        $this->makeRoomForOurBackup($server);

        try {
            $backup = $this->initiateBackup->handle($server, self::BACKUP_PREFIX . ' ' . $label, false);
        } catch (TooManyBackupsException) {
            throw new ModpackInstallException(
                'The server has reached its backup limit and no ModpackHub backup slot could be freed '
                . '(the remaining slots are user backups or locked). Free a backup slot or raise the '
                . 'server backup limit, then try again.'
            );
        }

        $deadline = time() + self::BACKUP_TIMEOUT;

        while (time() < $deadline) {
            sleep(self::POLL_SECONDS);
            $backup->refresh();

            if ($backup->completed_at === null) {
                continue;
            }
            if (!$backup->is_successful) {
                throw new ModpackInstallException('The backup failed: installation aborted.');
            }

            return $backup->uuid;
        }

        throw new ModpackInstallException('The backup did not finish within 15 minutes: installation aborted.');
    }

    private function nonFailedBackupCount(Server $server): int
    {
        return $server->backups()
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('is_successful', true))
            ->count();
    }

    private function makeRoomForOurBackup(Server $server): void
    {
        if ($this->nonFailedBackupCount($server) < (int) $server->backup_limit) {
            return;
        }

        $ours = $server->backups()
            ->where('name', 'like', self::BACKUP_PREFIX . '%')
            ->where('is_locked', false)
            ->orderBy('created_at')
            ->first();

        if (!$ours) {
            return;
        }

        $this->deleteBackup->handle($ours);

        ModpackInstallation::query()
            ->where('server_id', $server->id)
            ->where('backup_ref', $ours->uuid)
            ->update(['backup_ref' => null]);
    }

    public function hasDiskSpace(Server $server, int $packSizeBytes): bool
    {
        $limitBytes = ((int) $server->disk) * 1024 * 1024;
        if ($limitBytes <= 0) {
            return true;
        }

        $required = $packSizeBytes * 2 + self::DISK_MARGIN_BYTES;

        $used = 0;
        try {
            $used = (int) ($this->daemonServers->setServer($server)->getDetails()['utilization']['disk_bytes'] ?? 0);
        } catch (\Throwable) {
        }

        return ($limitBytes - $used) >= $required;
    }

    public function putInstallFiles(Server $server, string $content): void
    {
        try {
            $this->daemonFiles->setServer($server)->putContent(self::FILES_LIST, $content);
        } catch (\Throwable $e) {
            throw new ModpackInstallException(
                'The mod list could not be written to the node: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function readInstallResult(Server $server): ?string
    {
        try {
            $raw = $this->daemonFiles->setServer($server)->getContent(self::RESULT_FILE, 4096);
        } catch (\Throwable) {
            return null;
        }

        $first = trim(strtok($raw, "\n") ?: '');

        return $first === '' ? null : mb_substr($first, 0, 500);
    }

    public function cleanupTemp(Server $server): void
    {
        try {
            $this->daemonFiles->setServer($server)->deleteFiles('/', [self::TEMP_DIR, self::RESULT_FILE, self::FILES_LIST]);
        } catch (\Throwable) {
        }
    }
}
