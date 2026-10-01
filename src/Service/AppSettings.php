<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Persists API runtime settings to a JSON file under var/.
 */
final class AppSettings
{
    public const SCAN_METHODS = ['text', 'imagick', 'cli-zbar'];

    private const DEFAULTS = [
        'api_access_token' => '',
        'api_enabled' => true,
        'enabled_scan_methods' => self::SCAN_METHODS,
        'admin_allowed_ips' => [],
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $settingsFile,
        private readonly string $envAccessToken = '',
    ) {
    }

    /**
     * @return array{
     *     api_access_token: string,
     *     api_enabled: bool,
     *     enabled_scan_methods: list<string>,
     *     admin_allowed_ips: list<string>
     * }
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->normalize($this->cache);
        }

        if (!is_file($this->settingsFile)) {
            $this->cache = self::DEFAULTS;

            return $this->normalize($this->cache);
        }

        $raw = file_get_contents($this->settingsFile);
        if ($raw === false || $raw === '') {
            $this->cache = self::DEFAULTS;

            return $this->normalize($this->cache);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->cache = self::DEFAULTS;

            return $this->normalize($this->cache);
        }

        $this->cache = array_merge(self::DEFAULTS, $decoded);

        return $this->normalize($this->cache);
    }

    public function getApiAccessToken(): string
    {
        $token = trim((string) $this->all()['api_access_token']);

        return $token !== '' ? $token : $this->envAccessToken;
    }

    public function isApiEnabled(): bool
    {
        return (bool) $this->all()['api_enabled'];
    }

    /**
     * @return list<string>
     */
    public function getEnabledScanMethods(): array
    {
        return $this->all()['enabled_scan_methods'];
    }

    public function isScanMethodEnabled(string $name): bool
    {
        return in_array($name, $this->getEnabledScanMethods(), true);
    }

    /**
     * Extra admin IPs from settings (merged with ADMIN_ALLOWED_IPS from .env).
     *
     * @return list<string>
     */
    public function getAdminAllowedIps(): array
    {
        return $this->all()['admin_allowed_ips'];
    }

    /**
     * @param array{
     *     api_access_token?: string,
     *     api_enabled?: bool,
     *     enabled_scan_methods?: list<string>,
     *     admin_allowed_ips?: string|list<string>
     * } $data
     */
    public function save(array $data): void
    {
        $current = $this->all();

        if (array_key_exists('api_access_token', $data)) {
            $current['api_access_token'] = trim((string) $data['api_access_token']);
        }

        if (array_key_exists('api_enabled', $data)) {
            $current['api_enabled'] = (bool) $data['api_enabled'];
        }

        if (array_key_exists('enabled_scan_methods', $data)) {
            $methods = $data['enabled_scan_methods'];
            if (!is_array($methods)) {
                $methods = [];
            }
            $current['enabled_scan_methods'] = array_values(array_intersect(
                self::SCAN_METHODS,
                array_map('strval', $methods)
            ));
        }

        if (array_key_exists('admin_allowed_ips', $data)) {
            $current['admin_allowed_ips'] = $this->parseIpList($data['admin_allowed_ips']);
        }

        $dir = dirname($this->settingsFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create settings directory: %s', $dir));
        }

        $json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->settingsFile, $json."\n", LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write settings file: %s', $this->settingsFile));
        }

        $this->cache = $current;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *     api_access_token: string,
     *     api_enabled: bool,
     *     enabled_scan_methods: list<string>,
     *     admin_allowed_ips: list<string>
     * }
     */
    private function normalize(array $data): array
    {
        $methods = $data['enabled_scan_methods'] ?? self::SCAN_METHODS;
        if (!is_array($methods)) {
            $methods = self::SCAN_METHODS;
        }

        $methods = array_values(array_intersect(
            self::SCAN_METHODS,
            array_map('strval', $methods)
        ));

        return [
            'api_access_token' => (string) ($data['api_access_token'] ?? ''),
            'api_enabled' => (bool) ($data['api_enabled'] ?? true),
            'enabled_scan_methods' => $methods,
            'admin_allowed_ips' => $this->parseIpList($data['admin_allowed_ips'] ?? []),
        ];
    }

    /**
     * @param string|list<mixed> $value
     *
     * @return list<string>
     */
    private function parseIpList(string|array $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,;]+/', $value) ?: [];
        } else {
            $parts = $value;
        }

        $ips = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            if (filter_var($part, FILTER_VALIDATE_IP)) {
                $ips[] = $part;
                continue;
            }

            if (preg_match('#^([^/]+)/(\d{1,3})$#', $part, $m) === 1
                && filter_var($m[1], FILTER_VALIDATE_IP)
                && (int) $m[2] >= 0
                && (int) $m[2] <= (str_contains($m[1], ':') ? 128 : 32)
            ) {
                $ips[] = $part;
            }
        }

        return array_values(array_unique($ips));
    }
}
