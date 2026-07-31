<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Services\Servers\StartupModificationService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackInstallException;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;

class EggSwitchService
{
    private const POLL_SECONDS = 5;
    private const SETTING_EGG_ID = 'installer_egg_id';

    public const INSTALL_TIMEOUT = 3600;

    public function __construct(
        private StartupModificationService $startup,
        private ReinstallServerService $reinstall,
        private BlueprintAdminLibrary $blueprint,
    ) {}

    public function snapshot(Server $server): array
    {
        $env = [];
        foreach ($server->variables()->get() as $var) {
            $env[$var->env_variable] = $var->server_value ?? '';
        }

        return [
            'egg_id' => (int) $server->egg_id,
            'nest_id' => (int) $server->nest_id,
            'startup' => (string) $server->startup,
            'image' => (string) $server->image,
            'skip_scripts' => (bool) $server->skip_scripts,
            'environment' => $env,
        ];
    }

    public function switchToInstaller(Server $server, array $vars): void
    {
        $eggId = $this->installerEggId();
        $egg = Egg::query()->findOrFail($eggId);

        $this->startup->setUserLevel(User::USER_LEVEL_ADMIN)->handle($server, [
            'egg_id' => $eggId,
            'startup' => $egg->startup,
            'docker_image' => array_values($egg->docker_images)[0] ?? $server->image,
            'skip_scripts' => false,
            'environment' => $vars,
        ]);

        $this->reinstall->handle($server->refresh());
    }

    public function waitForInstall(Server $server, int $timeoutSec = self::INSTALL_TIMEOUT): bool
    {
        $deadline = time() + $timeoutSec;

        while (time() < $deadline) {
            sleep(self::POLL_SECONDS);

            $status = $server->refresh()->status;

            if ($status === null) {
                return true;
            }
            if (in_array($status, [Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED], true)) {
                return false;
            }
        }

        return false;
    }

    public function restore(Server $server, array $snapshot): void
    {
        if (empty($snapshot['egg_id'])) {
            throw new ModpackInstallException('Egg snapshot missing or corrupted: cannot restore automatically.');
        }

        $installerEggId = (int) $this->blueprint->dbGet('modpackhub', self::SETTING_EGG_ID, 0);

        $this->startup->setUserLevel(User::USER_LEVEL_ADMIN)->handle($server, [
            'egg_id' => (int) $snapshot['egg_id'],
            'startup' => $snapshot['startup'] ?? $server->startup,
            'docker_image' => $snapshot['image'] ?? $server->image,
            'skip_scripts' => (bool) ($snapshot['skip_scripts'] ?? false),
            'environment' => $snapshot['environment'] ?? [],
        ]);

        if ($installerEggId > 0 && $installerEggId !== (int) $snapshot['egg_id']) {
            $installerVarIds = EggVariable::query()->where('egg_id', $installerEggId)->pluck('id');
            ServerVariable::query()
                ->where('server_id', $server->id)
                ->whereIn('variable_id', $installerVarIds)
                ->delete();
        }
    }

    public function installerEggId(): int
    {
        $id = (int) $this->blueprint->dbGet('modpackhub', self::SETTING_EGG_ID, 0);

        if ($id <= 0 || !Egg::query()->whereKey($id)->exists()) {
            throw new ModpackInstallException(
                'ModpackHub installer egg not imported. An administrator must click '
                . '"Import/update installer egg" in the extension admin page.'
            );
        }

        return $id;
    }
}
