<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Pterodactyl\Http\Controllers\Controller;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Models\ModpackInstallation;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Jobs\InstallModpackJob;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\ModrinthService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\CurseForgeService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\CurseForgeManifestService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\UrlPackService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\WingsFileService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\ServerStateService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackSourceException;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackInstallException;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary;

class ModpackClientController extends Controller
{
    public function __construct(
        private ModrinthService $modrinth,
        private CurseForgeService $curseforge,
        private CurseForgeManifestService $cfManifest,
        private UrlPackService $urlpack,
        private WingsFileService $files,
        private ServerStateService $state,
        private BlueprintClientLibrary $blueprint,
    ) {}

    public function config(): JsonResponse
    {
        $enabled = array_filter(array_map('trim', explode(',', (string) $this->blueprint->dbGet('modpackhub', 'sources_enabled', 'modrinth,curseforge,url'))));

        $sources = array_values(array_filter($enabled, fn ($s) => $s !== 'curseforge' || $this->curseforge->enabled()));

        return response()->json([
            'sources' => $sources,
            'max_pack_mb' => (int) $this->blueprint->dbGet('modpackhub', 'max_pack_mb', 2048),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => 'required|string',
            'q' => 'nullable|string|max:128',
            'mc_version' => 'nullable|string|max:32',
            'loader' => 'nullable|string|max:16',
            'page' => 'nullable|integer|min:1',
        ]);

        $source = $this->assertSourceEnabled($data['source']);
        $page = (int) ($data['page'] ?? 1);

        return $this->guardSource(fn () => response()->json(match ($source) {
            'modrinth' => $this->modrinth->search($data['q'] ?? '', $data['mc_version'] ?? null, $data['loader'] ?? null, $page),
            'curseforge' => $this->curseforge->search($data['q'] ?? '', $data['mc_version'] ?? null, $data['loader'] ?? null, $page),
            default => throw new NotFoundHttpException("Source '{$source}' is not searchable (use a direct URL)."),
        }));
    }

    public function versions(Request $request, string $source, string $packId): JsonResponse
    {
        $source = $this->assertSourceEnabled($source);

        $data = $request->validate([
            'mc_version' => 'nullable|string|max:32',
            'loader' => 'nullable|string|max:16',
        ]);

        return $this->guardSource(function () use ($source, $packId, $data) {
            $versions = match ($source) {
                'modrinth' => $this->modrinth->versions($packId),
                'curseforge' => $this->curseforge->versions($packId, $data['mc_version'] ?? null, $data['loader'] ?? null),
                default => throw new NotFoundHttpException("Source '{$source}' has no version list."),
            };

            $blocked = ($versions === [] && $source === 'curseforge')
                ? $this->curseforge->emptyVersionsReason($packId)
                : null;

            return response()->json(['data' => $versions, 'blocked' => $blocked]);
        });
    }

    public function preflight(string $source, string $packId, string $versionId): JsonResponse
    {
        $source = $this->assertSourceEnabled($source);

        if ($source !== 'curseforge') {
            return response()->json(['applicable' => false]);
        }

        return $this->guardSource(function () use ($packId, $versionId) {
            $resolved = $this->curseforge->resolve($packId, $versionId);

            if (($resolved['format'] ?? null) !== CurseForgeService::LAYOUT_MANIFEST) {
                return response()->json(['applicable' => false]);
            }

            try {
                $list = $this->cfManifest->fileList($resolved['pack_url'], $resolved['size'] ?? null);
            } catch (ModpackInstallException $e) {
                return response()->json(['applicable' => true, 'blocked' => $e->getMessage()]);
            }

            if ($list === null) {
                return response()->json(['applicable' => false]);
            }

            return response()->json([
                'applicable' => true,
                'mods' => $list['count'],
                'client_only_skipped' => $list['client_only'],

                'from_modrinth' => $list['recovered'],
                'unavailable' => array_values($list['unavailable']),
            ]);
        });
    }

    public function install(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, Permission::ACTION_SETTINGS_REINSTALL);

        $data = $request->validate([
            'source' => 'required|string',
            'pack_id' => 'nullable|string|max:128',
            'version_id' => 'nullable|string|max:128',
            'url' => 'nullable|string|max:2048',
            'accept_eula' => 'required|boolean',
            'install_loader' => 'nullable|boolean',
            'backup' => 'nullable|boolean',
            'replace_mods' => 'nullable|boolean',
            'allow_missing' => 'nullable|boolean',
        ]);

        $source = $this->assertSourceEnabled($data['source']);

        if (ModpackInstallation::query()->where('server_id', $server->id)->active()->exists()) {
            throw new ConflictHttpException('An installation is already in progress for this server.');
        }

        if (in_array($server->status, [Server::STATUS_INSTALLING, Server::STATUS_RESTORING_BACKUP], true)) {
            throw new ConflictHttpException("The server is busy ({$server->status}): retry once it has finished.");
        }

        $resolved = $this->guardSourceValue(fn () => match ($source) {
            'url' => $this->resolveUrl($data),
            'modrinth' => $this->modrinth->resolve($this->required($data, 'version_id')),
            'curseforge' => $this->curseforge->resolve($this->required($data, 'pack_id'), $this->required($data, 'version_id')),
        });

        $resolved['accept_eula'] = (bool) $data['accept_eula'];
        $resolved['install_loader'] = (bool) ($data['install_loader'] ?? false);

        $resolved['backup'] = (bool) ($data['backup'] ?? true);

        $resolved['replace_mods'] = (bool) ($data['replace_mods'] ?? true);

        $resolved['allow_missing'] = (bool) ($data['allow_missing'] ?? false);

        $installation = ModpackInstallation::create([
            'server_id' => $server->id,
            'source' => $source,
            'pack_id' => $resolved['pack_id'] ?? ($data['pack_id'] ?? null),
            'version_id' => $resolved['version_id'] ?? ($data['version_id'] ?? null),
            'pack_name' => $resolved['pack_name'] ?? 'modpack',
            'mc_version' => $resolved['mc_version'] ?? null,
            'loader' => $resolved['loader'] ?? null,
            'status' => 'pending',
        ]);

        InstallModpackJob::dispatch($server, $resolved, $installation->id);

        return response()->json(['installation_id' => $installation->id], 202);
    }

    public function installs(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, Permission::ACTION_STARTUP_READ);

        $rows = ModpackInstallation::query()
            ->where('server_id', $server->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'source', 'pack_name', 'mc_version', 'loader', 'status', 'error_message', 'notes', 'backup_ref', 'created_at', 'updated_at']);

        $shape = fn ($r) => [
            'id' => $r->id,
            'source' => $r->source,
            'pack_name' => $r->pack_name,
            'mc_version' => $r->mc_version,
            'loader' => $r->loader,
            'status' => $r->status,
            'error_message' => $r->error_message,

            'notes' => $r->notes,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];

        $current = $this->state->currentInstallation($server);

        return response()->json([
            'data' => $rows->map($shape),
            'current' => $current ? $shape($current) : null,
            'backups_allowed' => $this->files->backupsAllowed($server),
        ]);
    }

    private function authorizeServer(Request $request, Server $server, string $permission): void
    {
        $user = $request->user();

        $isMember = $user->root_admin
            || $server->owner_id === $user->id
            || $server->subusers()->where('user_id', $user->id)->exists();

        if (!$isMember) {
            throw new NotFoundHttpException();
        }
        if (!$user->can($permission, $server)) {
            throw new AuthorizationException();
        }
    }

    private function assertSourceEnabled(string $source): string
    {
        $source = strtolower(trim($source));

        $enabled = array_filter(array_map('trim', explode(',', (string) $this->blueprint->dbGet('modpackhub', 'sources_enabled', 'modrinth,curseforge,url'))));

        if (!in_array($source, $enabled, true)) {
            throw new NotFoundHttpException("Source '{$source}' is not enabled.");
        }
        if ($source === 'curseforge' && !$this->curseforge->enabled()) {
            throw new NotFoundHttpException('CurseForge source not configured (API key missing).');
        }

        return $source;
    }

    private function resolveUrl(array $data): array
    {
        if (empty($data['url'])) {
            throw new ModpackSourceException('url: the url field is required when source=url.');
        }

        return $this->urlpack->resolve($data['url']);
    }

    private function required(array $data, string $key): string
    {
        if (empty($data[$key])) {
            throw new ModpackSourceException("The '{$key}' field is required for this source.");
        }

        return (string) $data[$key];
    }

    private function guardSource(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (ModpackSourceException $e) {
            throw new HttpException(502, $e->getMessage());
        }
    }

    private function guardSourceValue(callable $fn): array
    {
        try {
            return $fn();
        } catch (ModpackSourceException $e) {
            throw new HttpException(502, $e->getMessage());
        }
    }
}
