<?php
// WppSender.php — envoi WhatsApp via Evolution API
declare(strict_types=1);

require_once __DIR__ . '/../EvoApi.php';   // formes de payload v1 / v2
require_once __DIR__ . '/../Sandbox.php';

final class WppSender
{
    // ── API publique ──────────────────────────────────────────────────────────

    /** Message direct (ou groupe si chatId finit par @g.us). Renvoie la réponse Evolution (key.id → journal du fil). */
    public static function sendText(int $tenantId, string $chatId, string $text): array
    {
        return self::postFB($tenantId, $chatId, $text);
    }

    /** Message dans un groupe WhatsApp (groupId sans @g.us). */
    public static function sendGroup(int $tenantId, string $groupId, string $text): void
    {
        self::postFB($tenantId, "{$groupId}@g.us", $text);
    }

    /** Envoi d'un média/document (ex. PDF de certificat) via Evolution API. */
    public static function sendMedia(
        int    $tenantId,
        string $chatId,
        string $mediaUrl,
        string $fileName,
        string $caption = '',
        string $mediatype = 'document',
        string $mimetype = 'application/pdf'
    ): void {
        if (nfg_is_sandbox_tenant($tenantId)) { nfg_sandbox_log($tenantId, 'whatsapp média', $chatId, $fileName); return; }
        try {
            self::postMedia(self::config($tenantId), $chatId, $mediaUrl, $fileName, $caption, $mediatype, $mimetype);
        } catch (\Throwable $e) {
            if ($tenantId === 1) throw $e;
            error_log("[WppSender] tenant {$tenantId} média indisponible ({$e->getMessage()}) — fallback WhatsApp tenant 1");
            self::postMedia(self::config(1), $chatId, $mediaUrl, $fileName, $caption, $mediatype, $mimetype);
        }
    }

    private static function postMedia(
        array  $wa,
        string $chatId,
        string $mediaUrl,
        string $fileName,
        string $caption,
        string $mediatype,
        string $mimetype = 'application/pdf'
    ): void {
        $url = rtrim($wa['wa_connect_url'], '/') . '/message/sendMedia/' . $wa['wa_instance'];

        // Fiabilité : on envoie les OCTETS en base64 (et non l'URL) — évite les
        // « En attente de ce message » côté destinataire quand Evolution n'uploade
        // pas le média à temps depuis l'URL.
        $media = $mediaUrl;
        if (preg_match('~^https?://~i', $mediaUrl)) {
            $bin = @file_get_contents($mediaUrl);
            if ($bin !== false && $bin !== '') $media = base64_encode($bin);
        }

        $payload = EvoApi::mediaBody($chatId, $media, $fileName, $caption, $mediatype, $mimetype);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_POST           => true,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                "apikey: {$wa['wa_token']}",
            ],
            \CURLOPT_POSTFIELDS => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            \CURLOPT_TIMEOUT    => 60,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("wpp_media_failed: HTTP {$code} → {$resp}");
        }
    }

    /**
     * Message lié à un événement : résout les variables {{event.*}} / {{tenant.*}}
     * au moment de l'envoi, puis envoie dans le groupe.
     */
    public static function sendEventMessage(
        int    $tenantId,
        int    $eventId,
        string $groupId,
        string $bodyTemplate
    ): void {
        $event  = self::fetchEvent($eventId);
        $tenant = self::fetchTenant($tenantId);

        if (!$event) throw new \RuntimeException("event_not_found:{$eventId}");

        $text = self::resolveVars($bodyTemplate, $event, $tenant);
        self::postFB($tenantId, "{$groupId}@g.us", $text);
    }

    // ── Privé ─────────────────────────────────────────────────────────────────

    /** Envoi texte avec fallback plateforme : on tente l'instance du tenant ;
     *  si elle est absente/déconnectée/sans token, on bascule sur le WhatsApp
     *  du tenant 1 : un tenant sans WhatsApp connecté émet via le numéro de la plateforme. */
    private static function postFB(int $tenantId, string $chatId, string $text): array
    {
        // Avant le repli sur le tenant 1 : sinon le bac à sable écrirait depuis le vrai numéro.
        if (nfg_is_sandbox_tenant($tenantId)) {
            nfg_sandbox_log($tenantId, 'whatsapp', $chatId, mb_substr($text, 0, 60));
            return ['key' => ['id' => 'sandbox-' . bin2hex(random_bytes(6))], '_sandbox' => true];
        }
        try {
            return self::post(self::config($tenantId), $chatId, $text);
        } catch (\Throwable $e) {
            if ($tenantId === 1) throw $e;
            error_log("[WppSender] tenant {$tenantId} indisponible ({$e->getMessage()}) — fallback WhatsApp tenant 1");
            // Émis par le numéro de la plateforme : le fil du tenant 1 en gardera la trace.
            $r = self::post(self::config(1), $chatId, $text);
            $r['_sent_by_tenant'] = 1;
            return $r;
        }
    }

    private static function config(int $tenantId): array
    {
        $url = rtrim((string)getenv('EVO_SERVER_URL'), '/');
        if (!$url) throw new \RuntimeException('EVO_SERVER_URL not set');

        $pdo = nfg_pdo_core();
        $st  = $pdo->prepare("SELECT wa_token FROM tenants WHERE id = ? LIMIT 1");
        $st->execute([$tenantId]);
        $row = $st->fetch();

        if (!$row || empty($row['wa_token'])) {
            throw new \RuntimeException("wa_token_missing for tenant {$tenantId} — QR not yet scanned");
        }

        return [
            'wa_connect_url' => $url,
            'wa_token'       => (string)$row['wa_token'],
            'wa_instance'    => 'tenant-' . $tenantId,
        ];
    }

    private static function post(array $wa, string $chatId, string $text): array
    {
        $url = rtrim($wa['wa_connect_url'], '/') . '/message/sendText/' . $wa['wa_instance'];
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_POST           => true,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                "apikey: {$wa['wa_token']}",
            ],
            \CURLOPT_POSTFIELDS => json_encode(EvoApi::textBody($chatId, $text), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            \CURLOPT_TIMEOUT    => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("wpp_send_failed: HTTP {$code} → {$resp}");
        }
        return json_decode((string)$resp, true) ?: [];
    }

    private static function fetchEvent(int $eventId): ?array
    {
        $pdo = nfg_pdo_app();
        $st  = $pdo->prepare("SELECT id, label, event_at, event_url, tz FROM events WHERE id = ? LIMIT 1");
        $st->execute([$eventId]);
        return $st->fetch() ?: null;
    }

    private static function fetchTenant(int $tenantId): array
    {
        $pdo = nfg_pdo_core();
        $st  = $pdo->prepare("SELECT id, name FROM tenants WHERE id = ? LIMIT 1");
        $st->execute([$tenantId]);
        return $st->fetch() ?: [];
    }

    private static function resolveVars(string $text, array $event, array $tenant): string
    {
        return strtr($text, [
            '{{event.label}}'     => $event['label']     ?? '',
            '{{event.event_at}}'  => $event['event_at']  ?? '',
            '{{event.event_url}}' => $event['event_url'] ?? '',
            '{{tenant.name}}'     => $tenant['name']     ?? '',
            '{{tenant.nom}}'      => $tenant['name']     ?? '',
        ]);
    }
}
