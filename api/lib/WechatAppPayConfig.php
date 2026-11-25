<?php

declare(strict_types=1);

final class WechatAppPayConfig
{
    public const DEFAULT_MERCHANT = '1005316293';
    public const LEGACY_APP_ID = 'wx46157a6bc73d7c5e';
    public const MERCHANTS = [
        '1114697941' => ['wechatpay_certs', '229FD3294B7AEF4E7B08B86C8A389041BACDFDE5'],
        '1750778233' => ['wechatpay_app_new', '261A2E4DAF0480A9C02B98F4EDF0B6547846101B'],
        '1005316293' => ['wechatpay_app_1117new', '72E8240E5ADE811E3FA7000F4D3DDB4D5EC9D82E'],
    ];

    public static function profiles(Database $db): array
    {
        $rows = $db->query('SELECT * FROM kwx_wechat_app_merchants');
        if ($rows === false) {
            throw new RuntimeException('Cannot read WeChat merchant profiles');
        }

        $profiles = [];
        foreach ($rows as $row) {
            $id = $row['merchant_id'];
            if (isset(self::MERCHANTS[$id])) {
                $profiles[$id] = $row;
            }
        }
        return $profiles;
    }

    public static function activeId(Database $db): string
    {
        $rows = $db->query('SELECT merchant_id FROM kwx_wechat_app_active WHERE id = 1');
        $id = $rows[0]['merchant_id'] ?? '';
        if (!isset(self::MERCHANTS[$id])) {
            throw new RuntimeException('Active WeChat merchant is missing');
        }
        return $id;
    }

    public static function activeProfile(Database $db): array
    {
        $rows = $db->query('SELECT m.* FROM kwx_wechat_app_active a
                           JOIN kwx_wechat_app_merchants m ON m.merchant_id = a.merchant_id
                           WHERE a.id = 1');
        $profile = $rows[0] ?? [];
        if (!isset(self::MERCHANTS[$profile['merchant_id'] ?? '']) || empty($profile['api_v3_key'])) {
            throw new RuntimeException('Active WeChat merchant is not configured');
        }
        return $profile;
    }

    public static function keyPath(): string
    {
        return getenv('KWX_WXPAY_MASTER_KEY_FILE') ?: '/www/secure/kwx-wechat-pay/master.key';
    }

    private static function masterKey(): string
    {
        $value = is_readable(self::keyPath()) ? trim((string) file_get_contents(self::keyPath())) : '';
        if (!preg_match('/\A[0-9a-f]{64}\z/i', $value)) {
            throw new RuntimeException('WeChat master key file is missing or invalid');
        }
        return hex2bin($value);
    }

    public static function validApiKey(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9]{32}\z/', $value) === 1;
    }

    public static function encryptKey(string $id, string $value): string
    {
        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA, $nonce, $tag, $id);
        if ($ciphertext === false) {
            throw new RuntimeException('Cannot encrypt WeChat APIv3 key');
        }
        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decryptKey(string $id, string $value): string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) !== 60) {
            throw new RuntimeException('Invalid stored WeChat APIv3 key');
        }
        $key = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16), $id
        );
        if ($key === false || !self::validApiKey($key)) {
            throw new RuntimeException('Cannot decrypt stored WeChat APIv3 key');
        }
        return $key;
    }
}
