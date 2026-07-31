<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Jobs;

use Pterodactyl\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Models\ModpackInstallation;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\InstallOrchestrator;

class InstallModpackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public const TIMEOUT = 7200;

    public int $timeout = self::TIMEOUT;

    public function __construct(
        public Server $server,
        public array $resolved,
        public int $installationId,
    ) {
        $this->queue = 'standard';
    }

    public function handle(InstallOrchestrator $orchestrator): void
    {
        $orchestrator->start($this->server, $this->resolved, $this->installationId);
    }

    public function failed(\Throwable $e): void
    {
        ModpackInstallation::query()->whereKey($this->installationId)->update([
            'status' => 'failed',
            'error_message' => 'Job aborted: ' . $e->getMessage(),
        ]);
    }
}
