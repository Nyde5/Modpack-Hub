<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackSourceException;

class ModrinthService
{
    private const BASE = 'https://api.modrinth.com/v2';
    private const USER_AGENT = 'modpackhub/0.1.0 (+https://github.com/Nyde/modpackhub)';
    private const TIMEOUT = 10;
    private const RETRIES = 2;
    private const CACHE_TTL = 300;
    private const PER_PAGE = 20;

    private const PROJECTS_CHUNK = 100;

    public function search(string $q, ?string $mcVersion = null, ?string $loader = null, int $page = 1): array
    {
        $facets = [['project_type:modpack']];
        if ($mcVersion) {
            $facets[] = ["versions:$mcVersion"];
        }
        if ($loader) {
            $facets[] = ["categories:$loader"];
        }

        $body = $this->get('/search', [
            'query' => $q,
            'facets' => json_encode($facets),
            'limit' => self::PER_PAGE,
            'offset' => max(0, $page - 1) * self::PER_PAGE,
        ]);

        return [
            'data' => array_map(fn (array $hit) => [
                'source' => 'modrinth',
                'id' => $hit['project_id'],
                'name' => $hit['title'],
                'summary' => $hit['description'] ?? '',
                'icon_url' => $hit['icon_url'] ?? null,
                'downloads' => $hit['downloads'] ?? 0,
                'updated_at' => $hit['date_modified'] ?? null,
            ], $body['hits'] ?? []),
            'page' => $page,
            'total' => $body['total_hits'] ?? 0,
        ];
    }

    public function versions(string $projectId): array
    {
        $versions = $this->get('/project/' . rawurlencode($projectId) . '/version');

        $out = [];
        foreach ($versions as $v) {
            if (!$this->packFile($v)) {
                continue;
            }
            $out[] = [
                'id' => $v['id'],
                'name' => $v['name'],
                'mc_versions' => $v['game_versions'] ?? [],
                'loader' => $v['loaders'][0] ?? 'none',
                'server_file' => true,

                'release_type' => in_array($v['version_type'] ?? '', ['release', 'beta', 'alpha'], true)
                    ? $v['version_type']
                    : null,
            ];
        }

        return $out;
    }

    public function resolve(string $versionId): array
    {
        $v = $this->get('/version/' . rawurlencode($versionId));
        $file = $this->packFile($v);

        if (!$file) {
            throw new ModpackSourceException("modrinth: version {$versionId} has no downloadable .mrpack file.");
        }

        return [
            'source' => 'modrinth',
            'pack_id' => $v['project_id'] ?? null,
            'version_id' => $v['id'],

            'pack_name' => $this->projectTitle($v['project_id'] ?? null) ?? ($v['name'] ?? $file['filename']),
            'pack_url' => $file['url'],
            'format' => 'mrpack',
            'loader' => $v['loaders'][0] ?? 'none',

            'loader_version' => null,
            'mc_version' => $v['game_versions'][0] ?? null,
            'size' => $file['size'] ?? null,
        ];
    }

    private function projectTitle(?string $projectId): ?string
    {
        if (!$projectId) {
            return null;
        }

        try {
            return $this->get('/project/' . rawurlencode($projectId))['title'] ?? null;
        } catch (ModpackSourceException) {
            return null;
        }
    }

    private function packFile(array $version): ?array
    {
        $mrpacks = array_filter(
            $version['files'] ?? [],
            fn (array $f) => str_ends_with(strtolower($f['filename'] ?? ''), '.mrpack')
        );

        foreach ($mrpacks as $f) {
            if ($f['primary'] ?? false) {
                return $f;
            }
        }

        return $mrpacks ? reset($mrpacks) : null;
    }

    public function byHashes(array $sha1): array
    {
        $hashes = array_values(array_unique(array_filter(array_map('strtolower', $sha1))));

        if ($hashes === []) {
            return [];
        }

        try {
            $found = $this->post('/version_files', ['hashes' => $hashes, 'algorithm' => 'sha1']);

            $projectIds = array_values(array_unique(array_filter(
                array_map(fn ($v) => $v['project_id'] ?? null, $found)
            )));

            $side = [];
            foreach (array_chunk($projectIds, self::PROJECTS_CHUNK) as $chunk) {
                foreach ($this->get('/projects', ['ids' => json_encode($chunk)]) as $p) {
                    if (isset($p['id'])) {
                        $side[$p['id']] = (string) ($p['server_side'] ?? 'unknown');
                    }
                }
            }

            $out = [];

            foreach ($found as $hash => $version) {
                $hash = strtolower((string) $hash);
                $pid = $version['project_id'] ?? null;

                $url = null;
                $filename = null;
                foreach ($version['files'] ?? [] as $file) {
                    if (strtolower((string) ($file['hashes']['sha1'] ?? '')) === $hash) {
                        $url = $file['url'] ?? null;
                        $filename = $file['filename'] ?? null;
                    }
                }

                $out[$hash] = [
                    'server_side' => $pid !== null ? ($side[$pid] ?? null) : null,
                    'url' => $url,
                    'filename' => $filename,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function post(string $path, array $body): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                ->timeout(self::TIMEOUT)
                ->retry(self::RETRIES, 250, throw: false)
                ->post(self::BASE . $path, $body);
        } catch (ConnectionException $e) {
            throw new ModpackSourceException('modrinth: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new ModpackSourceException("modrinth: HTTP {$response->status()} on {$path}.");
        }

        return $response->json() ?? [];
    }

    private function get(string $path, array $query = []): array
    {
        $key = 'modpackhub:modrinth:' . md5($path . '?' . http_build_query($query));

        return Cache::remember($key, self::CACHE_TTL, function () use ($path, $query) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                    ->timeout(self::TIMEOUT)
                    ->retry(self::RETRIES, 250, throw: false)
                    ->get(self::BASE . $path, $query);
            } catch (ConnectionException $e) {
                throw new ModpackSourceException('modrinth: ' . $e->getMessage(), 0, $e);
            }

            if ($response->failed()) {
                throw new ModpackSourceException("modrinth: HTTP {$response->status()} on {$path}.");
            }

            return $response->json() ?? [];
        });
    }
}
