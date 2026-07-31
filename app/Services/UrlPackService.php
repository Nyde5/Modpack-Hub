<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackSourceException;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary;

class UrlPackService
{
    private const TIMEOUT = 10;
    private const MAX_REDIRECTS = 3;
    private const ALLOWED_PORT = 443;

    private const ARCHIVE_TYPES = [
        'application/zip', 'application/x-zip-compressed', 'application/x-zip',
        'application/octet-stream', 'binary/octet-stream',
    ];

    public function __construct(private BlueprintClientLibrary $blueprint) {}

    public function validate(string $url): bool
    {
        $parts = parse_url($url);

        if (!$parts || !isset($parts['host'])) {
            throw new ModpackSourceException('url: invalid URL.');
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new ModpackSourceException('url: only https URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ModpackSourceException('url: credentials in the URL are not allowed.');
        }
        if (($parts['port'] ?? self::ALLOWED_PORT) !== self::ALLOWED_PORT) {
            throw new ModpackSourceException('url: only port 443 is allowed.');
        }

        foreach ($this->resolveIps($parts['host']) as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new ModpackSourceException("url: {$parts['host']} resolves to an internal address ($ip).");
            }
        }

        return true;
    }

    public function resolve(string $url): array
    {
        [$finalUrl, $response] = $this->head($url);

        $maxBytes = ((int) $this->blueprint->dbGet('modpackhub', 'max_pack_mb', 2048)) * 1024 * 1024;
        $size = (int) $response->header('Content-Length');

        if ($size <= 0) {
            throw new ModpackSourceException('url: the server does not declare the file size (Content-Length).');
        }
        if ($size > $maxBytes) {
            throw new ModpackSourceException(sprintf(
                'url: the pack is %d MB, over the %d MB limit.',
                intdiv($size, 1024 * 1024),
                intdiv($maxBytes, 1024 * 1024)
            ));
        }

        $path = parse_url($finalUrl, PHP_URL_PATH) ?: '';
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $isArchiveName = (bool) preg_match('/\.(zip|mrpack)$/i', $path);

        if (!$isArchiveName && !in_array($type, self::ARCHIVE_TYPES, true)) {
            throw new ModpackSourceException("url: the file does not look like an archive (Content-Type: {$type}).");
        }

        return [
            'source' => 'url',
            'pack_id' => null,
            'version_id' => null,

            'pack_name' => urldecode(basename($path)) ?: 'pack',
            'pack_url' => $finalUrl,
            'format' => str_ends_with(strtolower($path), '.mrpack') ? 'mrpack' : 'zip',
            'loader' => 'none',
            'loader_version' => null,
            'mc_version' => null,
            'size' => $size,
        ];
    }

    private function head(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->validate($url);

            try {
                $response = Http::withoutRedirecting()->timeout(self::TIMEOUT)->head($url);
            } catch (ConnectionException $e) {
                throw new ModpackSourceException('url: ' . $e->getMessage(), 0, $e);
            }

            if (!$response->redirect()) {
                if ($response->failed()) {
                    throw new ModpackSourceException("url: HTTP {$response->status()} on the pack.");
                }

                return [$url, $response];
            }

            $url = $this->absolutize((string) $response->header('Location'), $url);
        }

        throw new ModpackSourceException('url: too many redirects (max ' . self::MAX_REDIRECTS . ').');
    }

    private function absolutize(string $location, string $base): string
    {
        if ($location === '') {
            throw new ModpackSourceException('url: redirect without a Location header.');
        }
        if (str_starts_with($location, '/')) {
            $parts = parse_url($base);

            return 'https://' . $parts['host'] . $location;
        }

        return $location;
    }

    private function resolveIps(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
        );

        if (!$ips) {
            throw new ModpackSourceException("url: host {$host} cannot be resolved.");
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $long = ip2long($ip);
        if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
            return false;
        }

        return true;
    }
}
