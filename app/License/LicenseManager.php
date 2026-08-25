<?php
/**
 * Host Nibo ELMS License Client Engine
 * 
 * Website: https://hostnibo.com
 * Support: https://hostnibo.com/contact
 * License Server: https://lic.hostnibo.com
 */

namespace ClientLoginVerify\License;

use WHMCS\Database\Capsule;

class LicenseManager
{
    // 1. Host Nibo License Server URL
    public const DEFAULT_SERVER_URL  = 'https://lic.hostnibo.com';

    // 2. ELMS Product Key
    public const DEFAULT_PRODUCT_KEY = 'CLIENTLOGINVERIFY';

    // 3. WHMCS Addon Module Directory Name
    public const MODULE_NAME         = 'clientloginverify';

    // 4. Offline cache duration (15 minutes = 900 seconds)
    public const CACHE_TTL_SECONDS   = 900;

    private static ?self $instance = null;

    private string $serverUrl;
    private string $productKey;
    private string $cacheDir;

    public function __construct()
    {
        $this->serverUrl  = self::DEFAULT_SERVER_URL;
        $this->productKey = self::DEFAULT_PRODUCT_KEY;
        $this->cacheDir   = dirname(__DIR__, 2) . '/storage/license';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Gatekeeper check: returns true if active & valid, false otherwise.
     */
    public static function isLicensed(?bool $forceRemote = null): bool
    {
        if ($forceRemote === null) {
            $isAdmin = defined('ADMINAREA') && ADMINAREA;
            $forceRemote = $isAdmin;
        }
        return self::getInstance()->checkLicenseValid($forceRemote);
    }

    /**
     * Retrieve stored license key from WHMCS database.
     */
    public function getLicenseKey(): string
    {
        try {
            $key = Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->where('setting', 'license_key')
                ->value('value');
            return trim((string)$key);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Save license key in WHMCS database.
     */
    public function saveLicenseKey(string $key): void
    {
        $key = trim($key);
        try {
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => self::MODULE_NAME, 'setting' => 'license_key'],
                ['value' => $key]
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Get clean domain name for verification.
     */
    public function getDomain(): string
    {
        $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $domain = preg_replace('/:\d+$/', '', (string)$domain);
        $domain = strtolower(trim($domain));
        return preg_replace('/^www\./', '', $domain) ?: 'localhost';
    }

    /**
     * Get server IP address.
     */
    public function getIp(): string
    {
        $ip = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '');
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            $ip = gethostbyname(gethostname()) ?: '127.0.0.1';
        }
        return trim($ip);
    }

    public function checkLicenseValid(bool $forceRemote = true): bool
    {
        $key = $this->getLicenseKey();
        if (empty($key)) {
            return false;
        }

        if ($forceRemote) {
            $res = $this->verify(true);
            return !empty($res['status']);
        }

        $cached = $this->readCache($key, $this->getDomain());
        if ($cached !== null) {
            return !empty($cached['status']);
        }

        $res = $this->verify(true);
        return !empty($res['status']);
    }

    /**
     * Send verification call to license server.
     */
    public function verify(bool $forceRemote = true): array
    {
        $key    = $this->getLicenseKey();
        $domain = $this->getDomain();
        $ip     = $this->getIp();

        if (empty($key)) {
            return ['status' => false, 'message' => 'No license key entered.', 'data' => []];
        }

        if (!$forceRemote) {
            $cached = $this->readCache($key, $domain);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $res = $this->post('/api/license/verify', [
                'license_key' => $key,
                'domain'      => $domain,
                'ip'          => $ip,
                'product'     => $this->productKey,
            ]);

            if (!empty($res['status'])) {
                $this->writeCache($key, $domain, $ip, $res);
            } else {
                $this->clearCache($key, $domain);
            }

            return $res;
        } catch (\Throwable $e) {
            $cached = $this->readCache($key, $domain);
            if ($cached !== null) {
                return $cached;
            }
            return ['status' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Activate license on license server.
     */
    public function activate(string $newKey): array
    {
        $newKey = trim($newKey);
        if ($newKey !== '') {
            $this->saveLicenseKey($newKey);
        }

        $domain = $this->getDomain();
        $ip     = $this->getIp();

        try {
            $res = $this->post('/api/license/activate', [
                'license_key'     => $newKey,
                'domain'          => $domain,
                'ip'              => $ip,
                'product'         => $this->productKey,
                'server_hostname' => gethostname() ?: 'unknown',
            ]);

            if (!empty($res['status']) || ($res['message'] ?? '') === 'Already activated') {
                $this->writeCache($newKey, $domain, $ip, $res);
                return ['status' => true, 'message' => 'License activated successfully!'];
            }

            $this->clearCache($newKey, $domain);
            return $res;
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => 'Activation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get detailed license info for UI display.
     */
    public function getDetails(bool $refreshLive = false): array
    {
        $key = $this->getLicenseKey();
        $details = [
            'status'       => 'unlicensed',
            'expiry'       => 'Lifetime',
            'product_name' => 'Client Login Verify',
            'product_key'  => $this->productKey,
        ];

        if (!empty($key)) {
            $check = $refreshLive ? $this->verify(true) : ($this->readCache($key, $this->getDomain()) ?? $this->verify(true));

            if (!empty($check['status'])) {
                $details['status']       = 'active';
                $details['expiry']       = $check['data']['expiry'] ?? ($check['data']['expires_at'] ?? 'Lifetime');
                $details['product_name'] = $check['data']['product_name'] ?? ($check['data']['product'] ?? 'Client Login Verify');
                $details['product_key']  = $check['data']['product_key'] ?? $this->productKey;
            } else {
                $msg = strtolower($check['message'] ?? '');
                if (strpos($msg, 'suspend') !== false) {
                    $details['status'] = 'suspended';
                } elseif (strpos($msg, 'terminate') !== false) {
                    $details['status'] = 'terminated';
                } elseif (strpos($msg, 'domain') !== false) {
                    $details['status'] = 'domain_mismatch';
                } elseif (strpos($msg, 'expired') !== false || strpos($msg, 'expire') !== false) {
                    $details['status'] = 'expired';
                } elseif (strpos($msg, 'cancel') !== false) {
                    $details['status'] = 'cancelled';
                } elseif (strpos($msg, 'pending') !== false) {
                    $details['status'] = 'pending';
                } elseif (strpos($msg, 'mismatch') !== false || strpos($msg, 'product') !== false) {
                    $details['status'] = 'product_mismatch';
                } else {
                    $details['status'] = 'invalid';
                }
            }
        }

        $masked = !empty($key) && strlen($key) >= 8 
            ? substr($key, 0, 4) . '-****-****-' . substr($key, -4) 
            : (!empty($key) ? '****' : 'None');

        return [
            'license_key'  => $key,
            'masked_key'   => $masked,
            'status'       => $details['status'],
            'is_licensed'  => ($details['status'] === 'active'),
            'expiry_date'  => $details['expiry'],
            'domain'       => $this->getDomain(),
            'ip'           => $this->getIp(),
            'product_name' => $details['product_name'],
            'product_key'  => $details['product_key'],
            'server_url'   => $this->serverUrl,
        ];
    }

    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload);
        $ch = curl_init($this->serverUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
        ]);

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['status' => false, 'message' => 'Connection to license server failed: ' . $curlErr];
        }

        $decoded = json_decode((string)$resp, true);
        return is_array($decoded) ? $decoded : ['status' => false, 'message' => 'Invalid response from license server'];
    }

    private function getCacheFile(string $key, string $domain): string
    {
        return $this->cacheDir . '/lic_' . substr(hash('sha256', $key . '|' . $this->productKey . '|' . $domain), 0, 32) . '.json';
    }

    private function writeCache(string $key, string $domain, string $ip, array $payload): void
    {
        @file_put_contents(
            $this->getCacheFile($key, $domain),
            json_encode(['ts' => time(), 'domain' => $domain, 'payload' => $payload])
        );
    }

    private function readCache(string $key, string $domain): ?array
    {
        $file = $this->getCacheFile($key, $domain);
        if (!is_file($file)) return null;

        $data = json_decode(@file_get_contents($file) ?: '', true);
        if (!is_array($data) || (time() - (int)($data['ts'] ?? 0)) > self::CACHE_TTL_SECONDS) return null;
        if (strtolower($data['domain'] ?? '') !== strtolower($domain)) return null;

        return $data['payload'] ?? null;
    }

    private function clearCache(string $key, string $domain): void
    {
        @unlink($this->getCacheFile($key, $domain));
    }
}
