<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackSourceException;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary;

class CurseForgeService
{
    private const BASE = 'https://api.curseforge.com/v1';
    private const GAME_MINECRAFT = 432;
    private const CLASS_MODPACK = 4471;
    private const TIMEOUT = 10;
    private const RETRIES = 2;
    private const CACHE_TTL = 300;
    private const PER_PAGE = 20;

    private const BULK_CHUNK = 300;

    private const FILES_PAGE_SIZE = 50;

    private const SORT_POPULARITY = 2;
    private const SORT_DESC = 'desc';

    private const MAX_RESULTS = 10000;
    private const MAX_PAGE = 500;

    private const FILE_STATUS_INSTALLABLE = [4 , 10 ];

    private const RELEASE_TYPES = [1 => 'release', 2 => 'beta', 3 => 'alpha'];

    public const MSG_NO_DISTRIBUTION = 'The author of this pack does not allow automatic downloads '
        . '(allowModDistribution=false). Download it manually from CurseForge and install it with the "Direct URL" source.';

    public const LAYOUT_SERVERPACK = 'zip';
    public const LAYOUT_MANIFEST = 'cfpack';
    public const LAYOUT_MULTIMC = 'multimc';

    private const LOADERS = [
        'forge' => 1, 'cauldron' => 2, 'liteloader' => 3,
        'fabric' => 4, 'quilt' => 5, 'neoforge' => 6,
    ];

    public function __construct(private BlueprintClientLibrary $blueprint) {}

    public function enabled(): bool
    {
        return $this->key() !== '';
    }

    public function keyIsValid(string $key): bool
    {
        if (trim($key) === '') {
            return false;
        }

        try {
            return Http::withHeaders(['x-api-key' => trim($key), 'Accept' => 'application/json'])
                ->timeout(self::TIMEOUT)
                ->get(self::BASE . '/games/' . self::GAME_MINECRAFT)
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function search(string $q, ?string $mcVersion = null, ?string $loader = null, int $page = 1): array
    {
        $mcVersion = trim((string) $mcVersion) !== '' ? trim((string) $mcVersion) : null;
        $loaderType = $loader ? (self::LOADERS[strtolower($loader)] ?? null) : null;

        $ignoredFilters = [];
        if ($loaderType !== null && $mcVersion === null) {
            $loaderType = null;
            $ignoredFilters[] = 'loader';
        }

        $page = max(1, min($page, self::MAX_PAGE));

        $body = $this->get('/mods/search', array_filter([
            'gameId' => self::GAME_MINECRAFT,
            'classId' => self::CLASS_MODPACK,
            'searchFilter' => $q,
            'gameVersion' => $mcVersion,
            'modLoaderType' => $loaderType,
            'sortField' => self::SORT_POPULARITY,
            'sortOrder' => self::SORT_DESC,
            'index' => ($page - 1) * self::PER_PAGE,
            'pageSize' => self::PER_PAGE,
        ], fn ($v) => $v !== null && $v !== ''));

        return [
            'data' => array_map(fn (array $mod) => [
                'source' => 'curseforge',
                'id' => (string) $mod['id'],
                'name' => $mod['name'],
                'summary' => $mod['summary'] ?? '',
                'icon_url' => $mod['logo']['thumbnailUrl'] ?? ($mod['logo']['url'] ?? null),
                'downloads' => $mod['downloadCount'] ?? 0,
                'updated_at' => $mod['dateModified'] ?? null,

                'distributable' => ($mod['allowModDistribution'] ?? true) !== false,
            ], $body['data'] ?? []),
            'page' => $page,

            'total' => min((int) ($body['pagination']['totalCount'] ?? 0), self::MAX_RESULTS),
            'ignored_filters' => $ignoredFilters,
        ];
    }

    public function versions(string $modId, ?string $mcVersion = null, ?string $loader = null): array
    {
        $loaderType = $loader ? (self::LOADERS[strtolower($loader)] ?? null) : null;

        $files = $this->get('/mods/' . rawurlencode($modId) . '/files', array_filter([
            'gameVersion' => trim((string) $mcVersion) !== '' ? trim((string) $mcVersion) : null,
            'modLoaderType' => $loaderType,
            'pageSize' => self::FILES_PAGE_SIZE,
        ], fn ($v) => $v !== null && $v !== ''))['data'] ?? [];

        $out = [];
        foreach ($files as $f) {
            $hasServerPack = !empty($f['serverPackFileId']) || !empty($f['isServerPack']);

            if (empty($f['downloadUrl']) && !$hasServerPack) {
                continue;
            }

            if (isset($f['fileStatus']) && !in_array((int) $f['fileStatus'], self::FILE_STATUS_INSTALLABLE, true)) {
                continue;
            }

            $out[] = [
                'id' => (string) $f['id'],
                'name' => $f['displayName'] ?? $f['fileName'],
                'mc_versions' => $this->mcVersions($f),
                'loader' => $this->loader($f),
                'server_file' => $hasServerPack,

                'layout' => $this->layout($f),
                'release_type' => self::RELEASE_TYPES[(int) ($f['releaseType'] ?? 0)] ?? null,
            ];
        }

        return $out;
    }

    public function emptyVersionsReason(string $modId): ?string
    {
        try {
            $mod = $this->get('/mods/' . rawurlencode($modId))['data'] ?? [];
        } catch (ModpackSourceException) {
            return null;
        }

        return ($mod['allowModDistribution'] ?? true) === false ? self::MSG_NO_DISTRIBUTION : null;
    }

    public function resolve(string $modId, string $fileId): array
    {
        $file = $this->get('/mods/' . rawurlencode($modId) . '/files/' . rawurlencode($fileId))['data'] ?? [];

        if (!$file) {
            throw new ModpackSourceException("curseforge: file {$fileId} does not exist.");
        }

        if (!empty($file['serverPackFileId'])) {
            $serverPack = $this->get('/mods/' . rawurlencode($modId) . '/files/' . $file['serverPackFileId'])['data'] ?? null;

            if ($serverPack && $this->layout($serverPack) !== self::LAYOUT_MULTIMC) {
                $file = $serverPack;
            }
        }

        $layout = $this->layout($file);

        if ($layout === self::LAYOUT_MULTIMC) {
            throw new ModpackSourceException(
                'curseforge: this version only ships a MultiMC/Prism client instance, not a server pack. '
                . 'Pick another version of this pack, or download a server pack manually and use the "Direct URL" source.'
            );
        }

        if (empty($file['downloadUrl'])) {
            throw new ModpackSourceException('curseforge: ' . self::MSG_NO_DISTRIBUTION);
        }

        return [
            'source' => 'curseforge',
            'pack_id' => (string) $modId,
            'version_id' => (string) $file['id'],

            'pack_name' => $this->modName($modId) ?? ($file['displayName'] ?? $file['fileName']),
            'pack_url' => $file['downloadUrl'],

            'format' => $layout,
            'loader' => $this->loader($file),

            'loader_version' => null,
            'mc_version' => $this->mcVersions($file)[0] ?? null,
            'size' => $file['fileLength'] ?? null,
        ];
    }

    public function layout(array $file): string
    {
        $names = array_map(
            fn ($m) => strtolower(trim((string) ($m['name'] ?? ''))),
            $file['modules'] ?? []
        );

        if (in_array('manifest.json', $names, true)) {
            return self::LAYOUT_MANIFEST;
        }

        if (in_array('mmc-pack.json', $names, true) || in_array('instance.cfg', $names, true)) {
            return self::LAYOUT_MULTIMC;
        }

        return self::LAYOUT_SERVERPACK;
    }

    public function declaredSide(array $file): string
    {
        $tags = array_map(fn ($v) => strtolower(trim((string) $v)), $file['gameVersions'] ?? []);
        $client = in_array('client', $tags, true);
        $server = in_array('server', $tags, true);

        if ($client && !$server) {
            return 'client_only';
        }

        return $server ? 'server' : 'unknown';
    }

    public function sha1Of(array $file): ?string
    {
        foreach ($file['hashes'] ?? [] as $hash) {
            if ((int) ($hash['algo'] ?? 0) === 1 && !empty($hash['value'])) {
                return strtolower((string) $hash['value']);
            }
        }

        return null;
    }

    public function filesByIds(array $fileIds): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $fileIds)), fn (int $id) => $id > 0));
        $out = [];

        foreach (array_chunk($ids, self::BULK_CHUNK) as $chunk) {
            foreach ($this->post('/mods/files', ['fileIds' => $chunk])['data'] ?? [] as $f) {
                if (isset($f['id'])) {
                    $out[(int) $f['id']] = $f;
                }
            }
        }

        return $out;
    }

    private function modName(string $modId): ?string
    {
        try {
            return $this->get('/mods/' . rawurlencode($modId))['data']['name'] ?? null;
        } catch (ModpackSourceException) {
            return null;
        }
    }

    private function mcVersions(array $file): array
    {
        return array_values(array_filter(
            $file['gameVersions'] ?? [],
            fn (string $v) => (bool) preg_match('/^\d+\.\d+/', $v)
        ));
    }

    private function loader(array $file): string
    {
        foreach ($file['gameVersions'] ?? [] as $v) {
            if (isset(self::LOADERS[strtolower($v)])) {
                return strtolower($v);
            }
        }

        return 'none';
    }

    private function key(): string
    {
        return trim((string) $this->blueprint->dbGet('modpackhub', 'curseforge_api_key', ''));
    }

    private function get(string $path, array $query = []): array
    {
        if (!$this->enabled()) {
            throw new ModpackSourceException('curseforge: source disabled, the API key is missing (admin page).');
        }

        $cacheKey = 'modpackhub:curseforge:' . md5($path . '?' . http_build_query($query));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($path, $query) {
            try {
                $response = $this->client()->get(self::BASE . $path, $query);
            } catch (ConnectionException $e) {
                throw new ModpackSourceException('curseforge: ' . $e->getMessage(), 0, $e);
            }

            return $this->unwrap($response, $path);
        });
    }

    private function post(string $path, array $body): array
    {
        if (!$this->enabled()) {
            throw new ModpackSourceException('curseforge: source disabled, the API key is missing (admin page).');
        }

        try {
            $response = $this->client()->post(self::BASE . $path, $body);
        } catch (ConnectionException $e) {
            throw new ModpackSourceException('curseforge: ' . $e->getMessage(), 0, $e);
        }

        return $this->unwrap($response, $path);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['x-api-key' => $this->key(), 'Accept' => 'application/json'])
            ->timeout(self::TIMEOUT)
            ->retry(self::RETRIES, 250, throw: false);
    }

    private function unwrap(\Illuminate\Http\Client\Response $response, string $path): array
    {
        if ($response->status() === 403) {
            throw new ModpackSourceException('curseforge: API key rejected (403). Check it in the admin page.');
        }
        if ($response->failed()) {
            throw new ModpackSourceException("curseforge: HTTP {$response->status()} on {$path}.");
        }

        return $response->json() ?? [];
    }
}
