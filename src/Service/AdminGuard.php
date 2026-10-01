<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Password + IP whitelist gate for /admin_asl23.
 */
final class AdminGuard
{
    public const SESSION_KEY = '_admin_asl23_auth';

    public function __construct(
        private readonly string $adminPassword,
        private readonly string $envAllowedIps,
        private readonly AppSettings $settings,
    ) {
    }

    public function isIpAllowed(Request $request): bool
    {
        $clientIp = $request->getClientIp() ?? '';
        if ($clientIp === '') {
            return false;
        }

        $allowed = $this->getAllowedIps();
        if ($allowed === []) {
            return false;
        }

        foreach ($allowed as $entry) {
            if ($this->ipMatches($clientIp, $entry)) {
                return true;
            }
        }

        return false;
    }

    public function isAuthenticated(SessionInterface $session): bool
    {
        if ($this->adminPassword === '') {
            return false;
        }

        $stored = $session->get(self::SESSION_KEY);
        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($this->passwordFingerprint(), $stored);
    }

    public function attemptLogin(string $password, SessionInterface $session): bool
    {
        if ($this->adminPassword === '' || $password === '') {
            return false;
        }

        if (!hash_equals($this->adminPassword, $password)) {
            return false;
        }

        $session->migrate(true);
        $session->set(self::SESSION_KEY, $this->passwordFingerprint());

        return true;
    }

    public function logout(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
        $session->migrate(true);
    }

    /**
     * @return list<string>
     */
    public function getAllowedIps(): array
    {
        $fromEnv = $this->parseIpList($this->envAllowedIps);
        $fromSettings = $this->settings->getAdminAllowedIps();

        return array_values(array_unique([...$fromEnv, ...$fromSettings]));
    }

    /**
     * @return list<string>
     */
    public function getEnvAllowedIps(): array
    {
        return $this->parseIpList($this->envAllowedIps);
    }

    public function isPasswordConfigured(): bool
    {
        return $this->adminPassword !== '';
    }

    private function passwordFingerprint(): string
    {
        return hash('sha256', 'admin_asl23|'.$this->adminPassword);
    }

    /**
     * @return list<string>
     */
    private function parseIpList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $ips = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $ips[] = $part;
            }
        }

        return array_values(array_unique($ips));
    }

    private function ipMatches(string $clientIp, string $rule): bool
    {
        if ($clientIp === $rule) {
            return true;
        }

        // CIDR, e.g. 192.168.1.0/24
        if (str_contains($rule, '/') && filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return $this->ipInCidr($clientIp, $rule);
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($subnet === null || $mask === null || !is_numeric($mask)) {
            return false;
        }

        $mask = (int) $mask;
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $len = strlen($ipBin) * 8;
        if ($mask < 0 || $mask > $len) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $maskByte = (~((1 << (8 - $bits)) - 1)) & 0xFF;

        return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
    }
}
