<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Authentication;

/**
 * ProfileResolver.Cached: profiles looked up by name or UUID through the services that discovery
 * names, without blocking the tick. Results, found or not, are kept for ten minutes.
 */
final class ProfileFetcher
{
    private const CACHE_SECONDS = 600;

    private readonly \CurlMultiHandle $multi;
    /** @var array<int, array{handle: \CurlHandle, body: string, kind: string, key: string}> */
    private array $requests = [];
    /** @var list<array{string, string, \Closure}> lookups waiting for discovery */
    private array $waiting = [];
    /** @var array<string, list<\Closure>> callbacks by lookup key */
    private array $callbacks = [];
    /** @var array<string, array{?array, float}> */
    private array $cache = [];
    private ?array $endpoints = null;
    private int $nextRequest = 0;

    public function __construct()
    {
        $this->multi = curl_multi_init();
    }

    /** @param \Closure(?array): void $done given ['uuid' => 16 bytes, 'name' => ..., 'properties' => [...]] or null */
    public function byName(string $name, \Closure $done): void
    {
        $this->lookup('name', strtolower($name), $done);
    }

    /** @param string $uuid 32 hex digits */
    public function byId(string $uuid, \Closure $done): void
    {
        $this->lookup('id', $uuid, $done);
    }

    private function lookup(string $kind, string $value, \Closure $done): void
    {
        $key = $kind . ':' . $value;
        if (isset($this->cache[$key]) && $this->cache[$key][1] > microtime(true)) {
            $done($this->cache[$key][0]);
            return;
        }
        if (isset($this->callbacks[$key])) {
            $this->callbacks[$key][] = $done;
            return;
        }
        $this->callbacks[$key] = [$done];
        if ($this->endpoints === null) {
            $this->waiting[] = [$kind, $value];
            if (!$this->pending('discovery')) {
                $this->request('discovery', '', Discovery::URL);
            }
            return;
        }
        $this->start($kind, $value);
    }

    private function start(string $kind, string $value): void
    {
        if ($kind === 'name') {
            $this->request('name', $value, str_replace('{name}', rawurlencode($value), $this->endpoints['name']));
        } else {
            $this->request('id', $value, str_replace('{profileId}', $value, $this->endpoints['id']) . '?unsigned=false');
        }
    }

    private function pending(string $kind): bool
    {
        foreach ($this->requests as $request) {
            if ($request['kind'] === $kind) {
                return true;
            }
        }
        return false;
    }

    private function request(string $kind, string $key, string $url): void
    {
        $id = $this->nextRequest++;
        $handle = curl_init($url);
        $this->requests[$id] = ['handle' => $handle, 'body' => '', 'kind' => $kind, 'key' => $key];
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS_STR => 'https', CURLOPT_CONNECTTIMEOUT_MS => 3000, CURLOPT_TIMEOUT_MS => 10000,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'PHP-Minecraft-Java-Server/26.3',
            CURLOPT_WRITEFUNCTION => function ($unused, string $bytes) use ($id): int {
                if (!isset($this->requests[$id]) || strlen($this->requests[$id]['body']) + strlen($bytes) > 65536) {
                    return 0;
                }
                $this->requests[$id]['body'] .= $bytes;
                return strlen($bytes);
            }]);
        curl_multi_add_handle($this->multi, $handle);
    }

    /** Runs the callbacks of lookups that finished. */
    public function poll(): void
    {
        if ($this->requests === []) {
            return;
        }
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($message = curl_multi_info_read($this->multi)) {
            foreach ($this->requests as $id => $request) {
                if ($request['handle'] !== $message['handle']) {
                    continue;
                }
                $code = curl_getinfo($request['handle'], CURLINFO_RESPONSE_CODE);
                curl_multi_remove_handle($this->multi, $request['handle']);
                unset($this->requests[$id]);
                $body = $message['result'] === CURLE_OK && $code === 200 ? $request['body'] : null;
                $this->finished($request['kind'], $request['key'], $body);
                break;
            }
        }
    }

    private function finished(string $kind, string $key, ?string $body): void
    {
        $data = $body === null ? null : json_decode($body, true, 32);
        if ($kind === 'discovery') {
            $endpoints = $data['discovery'] ?? null;
            $name = $endpoints['profiles']['endpoints']['getByName']['uri'] ?? null;
            $id = $endpoints['session']['endpoints']['getProfileById']['uri'] ?? null;
            $waiting = $this->waiting;
            $this->waiting = [];
            if (!is_string($name) || !is_string($id) || !str_starts_with($name, 'https://') || !str_starts_with($id, 'https://')) {
                foreach ($waiting as [$waitingKind, $value]) {
                    $this->resolve($waitingKind . ':' . $value, null, false);
                }
                return;
            }
            $this->endpoints = ['name' => $name, 'id' => $id];
            foreach ($waiting as [$waitingKind, $value]) {
                $this->start($waitingKind, $value);
            }
            return;
        }
        $uuid = is_array($data) && is_string($data['id'] ?? null) && preg_match('/\A[0-9a-f]{32}\z/i', $data['id']) === 1 ? strtolower($data['id']) : null;
        if ($kind === 'name') {
            if ($uuid === null) {
                $this->resolve('name:' . $key, null, $body !== null || $data !== null);
                return;
            }
            // The name's UUID is then resolved like any ID, sharing its cache.
            $this->lookup('id', $uuid, fn (?array $profile) => $this->resolve('name:' . $key, $profile, true));
            return;
        }
        $profile = null;
        if ($uuid !== null) {
            try {
                $profile = Profile::parse((string) $body, (string) ($data['name'] ?? ''));
            } catch (\JsonException|\InvalidArgumentException) {
            }
        }
        $this->resolve('id:' . $key, $profile, $body !== null);
    }

    private function resolve(string $key, ?array $profile, bool $cache): void
    {
        if ($cache) {
            $this->cache[$key] = [$profile, microtime(true) + self::CACHE_SECONDS];
        }
        $callbacks = $this->callbacks[$key] ?? [];
        unset($this->callbacks[$key]);
        foreach ($callbacks as $callback) {
            $callback($profile);
        }
    }

    public function __destruct()
    {
        foreach ($this->requests as $request) {
            curl_multi_remove_handle($this->multi, $request['handle']);
        }
    }
}
