<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services;

use Illuminate\Support\Facades\Http;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Exceptions\ModpackInstallException;

class CurseForgeManifestService
{
    public const MAX_PACK_BYTES = 64 * 1024 * 1024;

    public const MAX_MANIFEST_BYTES = 4 * 1024 * 1024;

    public const MAX_FILES = 4096;

    private const CHUNK_BYTES = 262144;
    private const TIMEOUT = 60;

    private const TAIL_BYTES = 65536;

    private const MAX_CD_BYTES = 8 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    private const CDN_HOSTS = ['edge.forgecdn.net', 'mediafilez.forgecdn.net'];

    public function __construct(
        private CurseForgeService $curseforge,
        private ModrinthService $modrinth,
    ) {}

    public function fileList(string $packUrl, ?int $declaredSize = null): ?array
    {
        $raw = $this->manifestViaRange($packUrl, $declaredSize);

        if ($raw === null) {
            if ($declaredSize !== null && $declaredSize > self::MAX_PACK_BYTES) {
                throw new ModpackInstallException(sprintf(
                    'This CurseForge pack weighs %d MB and its CDN did not accept a partial read, so the panel '
                    . 'would have to download all of it (limit %d MB). Download the pack manually and use the '
                    . '"Direct URL" source.',
                    intdiv($declaredSize, 1024 * 1024),
                    intdiv(self::MAX_PACK_BYTES, 1024 * 1024)
                ));
            }

            $zipPath = $this->download($packUrl);

            try {
                $raw = $this->readManifestFromZip($zipPath);
            } finally {
                @unlink($zipPath);
            }
        }

        $manifest = $this->decodeManifest($raw);

        return $this->isCurseForgeModpackManifest($manifest) ? $this->resolveFiles($manifest) : null;
    }

    private function isCurseForgeModpackManifest(array $manifest): bool
    {
        if (($manifest['manifestType'] ?? null) !== 'minecraftModpack') {
            return false;
        }

        $files = $manifest['files'] ?? null;

        return is_array($files) && (($files === []) || is_array(reset($files)));
    }

    private function manifestViaRange(string $url, ?int $declaredSize): ?string
    {
        try {
            [$finalUrl, $size] = $this->resolveFinalUrl($url);
            $size = $size ?: (int) $declaredSize;

            if ($size <= 0) {
                return null;
            }

            $tailFrom = max(0, $size - self::TAIL_BYTES);
            $tail = $this->rangeGet($finalUrl, $tailFrom, $size - 1);

            if ($tail === null) {
                return null;
            }

            $eocd = strrpos($tail, "PK\x05\x06");

            if ($eocd === false || strlen($tail) - $eocd < 22) {
                return null;
            }

            $cdSize = $this->u32($tail, $eocd + 12);
            $cdOffset = $this->u32($tail, $eocd + 16);

            if ($cdOffset === 0xFFFFFFFF || $cdSize === 0xFFFFFFFF || $cdSize > self::MAX_CD_BYTES || $cdSize <= 0) {
                return null;
            }

            $cd = $cdOffset >= $tailFrom && ($cdOffset + $cdSize) <= ($tailFrom + strlen($tail))
                ? substr($tail, $cdOffset - $tailFrom, $cdSize)
                : $this->rangeGet($finalUrl, $cdOffset, $cdOffset + $cdSize - 1);

            if ($cd === null) {
                return null;
            }

            $entry = $this->findInCentralDirectory($cd, 'manifest.json');

            if ($entry === null) {
                throw new ModpackInstallException(
                    'The CurseForge pack has no manifest.json: its layout is not the one the API declared.'
                );
            }

            $local = $this->rangeGet($finalUrl, $entry['offset'], $entry['offset'] + 29);

            if ($local === null || !str_starts_with($local, "PK\x03\x04")) {
                return null;
            }

            $dataFrom = $entry['offset'] + 30 + $this->u16($local, 26) + $this->u16($local, 28);
            $data = $this->rangeGet($finalUrl, $dataFrom, $dataFrom + $entry['compressed'] - 1);

            if ($data === null) {
                return null;
            }

            return $this->inflate($data, $entry['method']);
        } catch (ModpackInstallException $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveFinalUrl(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (!str_starts_with($url, 'https://') || !in_array($host, self::CDN_HOSTS, true)) {
                throw new ModpackInstallException("The CurseForge pack URL points outside the CurseForge CDN ({$host}).");
            }

            $response = Http::timeout(self::TIMEOUT)->withoutRedirecting()->head($url);
            $location = $response->header('Location');

            if ($response->redirect() && $location !== '') {
                $url = $location;
                continue;
            }

            return [$url, (int) $response->header('Content-Length')];
        }

        throw new ModpackInstallException('The CurseForge pack URL redirects too many times.');
    }

    private function rangeGet(string $url, int $from, int $to): ?string
    {
        if ($to < $from || ($to - $from) > self::MAX_CD_BYTES) {
            return null;
        }

        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders(['Range' => "bytes={$from}-{$to}"])
            ->get($url);

        return $response->status() === 206 ? $response->body() : null;
    }

    private function findInCentralDirectory(string $cd, string $wanted): ?array
    {
        $pos = 0;
        $len = strlen($cd);

        while ($pos + 46 <= $len && substr($cd, $pos, 4) === "PK\x01\x02") {
            $nameLen = $this->u16($cd, $pos + 28);
            $extraLen = $this->u16($cd, $pos + 30);
            $commentLen = $this->u16($cd, $pos + 32);

            if (substr($cd, $pos + 46, $nameLen) === $wanted) {
                return [
                    'offset' => $this->u32($cd, $pos + 42),
                    'compressed' => $this->u32($cd, $pos + 20),
                    'method' => $this->u16($cd, $pos + 10),
                ];
            }

            $pos += 46 + $nameLen + $extraLen + $commentLen;
        }

        return null;
    }

    private function inflate(string $data, int $method): string
    {
        if ($method === 0) {
            return $data;
        }
        if ($method !== 8) {
            throw new ModpackInstallException("manifest.json uses an unsupported compression method ({$method}).");
        }

        $out = @gzinflate($data);

        if ($out === false) {
            throw new ModpackInstallException('manifest.json could not be decompressed.');
        }

        return $out;
    }

    private function u16(string $s, int $at): int
    {
        return (int) (unpack('v', substr($s, $at, 2))[1] ?? 0);
    }

    private function u32(string $s, int $at): int
    {
        return (int) (unpack('V', substr($s, $at, 4))[1] ?? 0);
    }

    private function decodeManifest(string $raw): array
    {
        if (strlen($raw) > self::MAX_MANIFEST_BYTES) {
            throw new ModpackInstallException('The manifest.json of this pack is implausibly large: refused.');
        }

        $manifest = json_decode($raw, true);

        if (!is_array($manifest)) {
            throw new ModpackInstallException('The manifest.json of this CurseForge pack is not valid JSON.');
        }

        return $manifest;
    }

    private function download(string $url): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mph-cf-');

        if ($tmp === false) {
            throw new ModpackInstallException('Could not create a temporary file to read the CurseForge manifest.');
        }

        $out = fopen($tmp, 'wb');

        try {
            if ($out === false) {
                throw new ModpackInstallException('Could not open a temporary file to read the CurseForge manifest.');
            }

            $response = Http::timeout(self::TIMEOUT)->withOptions(['stream' => true])->get($url);

            if ($response->failed()) {
                throw new ModpackInstallException(
                    "The CurseForge pack could not be downloaded (HTTP {$response->status()})."
                );
            }

            $body = $response->toPsrResponse()->getBody();
            $written = 0;

            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if ($written > self::MAX_PACK_BYTES) {
                    throw new ModpackInstallException(sprintf(
                        'The CurseForge pack exceeds the %d MB limit for a manifest pack: download aborted.',
                        intdiv(self::MAX_PACK_BYTES, 1024 * 1024)
                    ));
                }

                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);

            throw $e instanceof ModpackInstallException
                ? $e
                : new ModpackInstallException('The CurseForge pack could not be downloaded: ' . $e->getMessage(), 0, $e);
        }

        fclose($out);

        return $tmp;
    }

    private function readManifestFromZip(string $zipPath): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new ModpackInstallException('The CurseForge pack is not a readable zip archive.');
        }

        try {
            if ($zip->statName('manifest.json') === false) {
                throw new ModpackInstallException(
                    'The CurseForge pack has no manifest.json: its layout is not the one the API declared.'
                );
            }

            $stream = $zip->getStream('manifest.json');

            if ($stream === false) {
                throw new ModpackInstallException('manifest.json could not be read from the CurseForge pack.');
            }

            $raw = stream_get_contents($stream, self::MAX_MANIFEST_BYTES + 1);
            fclose($stream);
        } finally {
            $zip->close();
        }

        if ($raw === false) {
            throw new ModpackInstallException('manifest.json could not be read from the CurseForge pack.');
        }

        return $raw;
    }

    private function resolveFiles(array $manifest): array
    {
        $files = $manifest['files'] ?? null;

        if (!is_array($files) || $files === []) {
            throw new ModpackInstallException('The manifest of this CurseForge pack lists no files.');
        }
        if (count($files) > self::MAX_FILES) {
            throw new ModpackInstallException(sprintf(
                'This pack lists %d files, over the limit of %d: refusing to start an installation that would '
                . 'download them one by one.',
                count($files),
                self::MAX_FILES
            ));
        }

        $resolved = $this->curseforge->filesByIds(array_map(fn ($f) => (int) ($f['fileID'] ?? 0), $files));

        $mr = $this->modrinth->byHashes(array_filter(array_map(
            fn (array $f) => $this->curseforge->sha1Of($f),
            $resolved
        )));

        $lines = [];
        $bytes = 0;
        $skipped = 0;
        $clientOnly = 0;
        $recovered = 0;
        $unavailable = [];

        foreach ($files as $entry) {
            $id = (int) ($entry['fileID'] ?? 0);
            $file = $resolved[$id] ?? null;

            $name = basename(str_replace('\\', '/', (string) ($file['fileName'] ?? '')));

            if ($name !== '' && !str_ends_with(strtolower($name), '.jar')) {
                $skipped++;
                continue;
            }

            if ($file && $this->isClientOnly($file, $mr)) {
                $clientOnly++;
                continue;
            }

            $url = $file['downloadUrl'] ?? null;
            $fromModrinth = false;

            if (!$url && $file) {
                $sha1 = $this->curseforge->sha1Of($file);
                $url = $sha1 !== null ? ($mr[$sha1]['url'] ?? null) : null;
                $fromModrinth = $url !== null;
            }

            if (!$file || !$url) {
                $unavailable[] = $file['fileName'] ?? ('file ' . $id);
                continue;
            }

            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '..')) {
                throw new ModpackInstallException(
                    'A file in this pack has an unusable file name and cannot be installed safely (file ' . $id . ').'
                );
            }

            if ($fromModrinth) {
                $recovered++;
            }

            $lines[] = 'mods/' . $name . "\t" . $url;
            $bytes += (int) ($file['fileLength'] ?? 0);
        }

        if ($lines === []) {
            throw new ModpackInstallException('The manifest of this CurseForge pack contains no mod (.jar) to install.');
        }

        return [
            'content' => implode("\n", $lines) . "\n",
            'count' => count($lines),
            'bytes' => $bytes,
            'skipped' => $skipped,
            'client_only' => $clientOnly,
            'recovered' => $recovered,
            'unavailable' => $unavailable,
        ];
    }

    private function isClientOnly(array $file, array $mr): bool
    {
        $sha1 = $this->curseforge->sha1Of($file);
        $side = $sha1 !== null ? ($mr[$sha1]['server_side'] ?? null) : null;

        if ($side !== null) {
            return $side === 'unsupported';
        }

        return $this->curseforge->declaredSide($file) === 'client_only';
    }
}
