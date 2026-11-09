<?php
// global-db/_inc/EventSetup.php
// Orchestrateur d'initialisation d'un événement (appelé par le worker, type event.setup).
//
// Ordre d'exécution :
//   1. Charger event + product + product_wpp_policies
//   2. Créer le groupe WhatsApp si wa_group_id est NULL
//      → setIcon, setDescription, setAdminsOnly, add+promote admins
//      → sauver wa_group_id dans events
//   3. Créer le dossier/liste Brevo si brevo_list_id est NULL
//      → sauver brevo_list_id dans events
//   4. Annuler les messages WPP en attente existants pour cet event
//   5. Planifier les messages d'automation event_created
declare(strict_types=1);

require_once __DIR__ . '/senders/WppGroupSetup.php';
require_once __DIR__ . '/senders/BrevoSetup.php';

final class EventSetup
{
    // ── Point d'entrée ────────────────────────────────────────────────────────

    public static function run(int $tenantId, int $eventId): void
    {
        // Verrou applicatif : évite que le kick (setup-run) et le cron exécutent
        // le setup en parallèle (doublons groupe/liste/messages). 0 = non bloquant.
        $pdo      = nfg_pdo_app();
        $lockName = "evsetup_{$tenantId}_{$eventId}";
        $got      = (int)($pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0)")->fetchColumn());
        if ($got !== 1) {
            return; // un autre process traite déjà cet événement
        }

        try {
            self::writeState($tenantId, $eventId, ['phase' => 'running']);

            // 1. Charger event
            $event = self::loadEvent($tenantId, $eventId);
            if (!$event) {
                throw new \RuntimeException("event_not_found:{$eventId}");
            }

            $productId = (int)$event['product_id'];

            // 2. Charger product + politiques WPP
            $product = self::loadProduct($tenantId, $productId);
            if (!$product) {
                throw new \RuntimeException("product_not_found:{$productId}");
            }
            $wppPolicy = self::loadWppPolicy($productId);

            // 3. Groupe WhatsApp
            $waGroupId = $event['wa_group_id'] ?? null;
            if (!$waGroupId) {
                self::writeState($tenantId, $eventId, ['whatsapp' => ['status' => 'creating']]);
                $waGroupId = self::setupWppGroup($tenantId, $eventId, $event, $product, $wppPolicy);
            } elseif (empty($event['wa_invite_link'])) {
                // Groupe existe déjà mais le lien d'invitation est absent → le récupérer maintenant
                try {
                    $inviteLink = WppGroupSetup::getInviteLink($tenantId, (string)$waGroupId);
                    if ($inviteLink) {
                        nfg_pdo_app()
                            ->prepare("UPDATE events SET wa_invite_link = ? WHERE id = ? AND tenant_id = ?")
                            ->execute([$inviteLink, $eventId, $tenantId]);
                    }
                } catch (\Throwable $e) {
                    error_log("EventSetup::refreshInviteLink failed event={$eventId}: " . $e->getMessage());
                }
            }
            // État groupe : relire le lien d'invitation persisté
            $inv = (string)(nfg_query('app', "SELECT wa_invite_link FROM events WHERE id = ? AND tenant_id = ? LIMIT 1", [$eventId, $tenantId])[0]['wa_invite_link'] ?? '');
            self::writeState($tenantId, $eventId, ['whatsapp' => ['status' => 'ready', 'group_id' => (string)$waGroupId, 'invite_link' => $inv]]);

            // 4. Liste Brevo
            $brevoListId = $event['brevo_list_id'] ?? null;
            if (!$brevoListId) {
                $brevoListId = self::setupBrevoList($tenantId, $eventId, $event, $product);
            }
            self::writeState($tenantId, $eventId, ['brevo' => ['status' => 'ready', 'list_id' => (int)$brevoListId]]);

            // 5. Planifier les messages (écrit scheduling.* en interne)
            if ((string)($event['content_mode'] ?? 'repetitive') === 'per_session') {
                // Cohorte : 2 messages de Classe (annonce + jour-J) déclinés par session
                self::schedulePerSession($tenantId, $eventId, (string)$waGroupId, $event);
            } else {
                // Captation : automations event_created (modèle relatif aux dates)
                self::scheduleAutomations($tenantId, $eventId, $productId, (string)$waGroupId, (int)$brevoListId);
            }

            self::writeState($tenantId, $eventId, ['phase' => 'done']);
        } catch (\Throwable $e) {
            self::writeState($tenantId, $eventId, ['phase' => 'error', 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
            throw $e;
        }

        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    }

    /**
     * Met à jour events.setup_state (merge récursif non destructif) pour la
     * transparence côté UI (panneau de progression de l'éditeur).
     */
    private static function writeState(int $tenantId, int $eventId, array $patch): void
    {
        try {
            // Lecture en PDO DIRECT (pas nfg_query, qui est mis en cache 3600s →
            // les writeState successifs dans le même process reliraient une valeur
            // périmée et n'accumuleraient pas les sous-états).
            $rd = nfg_pdo_app()->prepare("SELECT setup_state FROM events WHERE id = ? AND tenant_id = ? LIMIT 1");
            $rd->execute([$eventId, $tenantId]);
            $cur   = $rd->fetchColumn();
            $cur   = ($cur === false) ? null : $cur;
            $state = $cur ? (json_decode((string)$cur, true) ?: []) : [];
            $state = array_replace_recursive($state, $patch);
            $state['updated_at'] = date('Y-m-d H:i:s');
            nfg_pdo_app()
                ->prepare("UPDATE events SET setup_state = ? WHERE id = ? AND tenant_id = ?")
                ->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $eventId, $tenantId]);
        } catch (\Throwable $e) {
            error_log("EventSetup::writeState failed event={$eventId}: " . $e->getMessage());
        }
    }

    // ── WPP Group ─────────────────────────────────────────────────────────────

    private static function setupWppGroup(
        int    $tenantId,
        int    $eventId,
        array  $event,
        array  $product,
        ?array $wppPolicy
    ): string {
        // Nom du groupe — basé sur la stratégie WPP du produit
        $strategy  = trim((string)($wppPolicy['strategy'] ?? 'per_event'));
        $baseName  = trim((string)($wppPolicy['base_name'] ?? ''));
        if ($baseName === '') $baseName = trim((string)($product['wpp_group_name'] ?? ''));
        if ($baseName === '') $baseName = trim((string)($product['name'] ?? 'Groupe'));

        $startDate = (string)($event['start_date'] ?? '');
        $endDate   = (string)($event['end_date']   ?? $startDate);
        $productId = (int)$event['product_id'];

        switch ($strategy) {
            case 'per_month':
                $groupName = $baseName . ' - ' . self::formatMonthFr($startDate);
                break;

            case '2_per_month':
                // Numéro de groupe dans le mois : compte les events du même produit
                // qui ont déjà un wa_group_id dans ce même mois calendaire
                $monthPrefix = $startDate ? substr($startDate, 0, 7) : date('Y-m');
                $stCount = nfg_pdo_app()->prepare("
                    SELECT COUNT(*) FROM events
                     WHERE product_id = ? AND tenant_id = ?
                       AND wa_group_id IS NOT NULL AND wa_group_id != ''
                       AND start_date LIKE ? AND id != ?
                ");
                $stCount->execute([$productId, $tenantId, $monthPrefix . '%', $eventId]);
                $num       = (int)$stCount->fetchColumn() + 1;
                $groupName = $num . '# ' . $baseName . ' - ' . self::formatMonthFr($startDate);
                break;

            case 'per_event':
            default:
                // "{base_name} - {start_date} - {end_date}" ou juste "{base_name} - {start_date}"
                if ($startDate && $endDate && $endDate !== $startDate) {
                    $groupName = $baseName . ' - ' . $startDate . ' - ' . $endDate;
                } elseif ($startDate) {
                    $groupName = $baseName . ' - ' . $startDate;
                } else {
                    $groupName = $baseName . ' — ' . trim((string)($event['label'] ?? ''));
                }
                break;
        }

        // Participants initiaux : phones des admins principaux du tenant
        $adminPhones = self::loadAdminPhones($tenantId);

        $groupId = WppGroupSetup::create($tenantId, $groupName, $adminPhones);

        // Icone
        $imageUrl = $wppPolicy['group_image_url'] ?? '';
        if ($imageUrl) {
            try {
                WppGroupSetup::setIcon($tenantId, $groupId, $imageUrl);
            } catch (\Throwable $e) {
                // Non bloquant — log et on continue
                error_log("EventSetup::setIcon failed event={$eventId}: " . $e->getMessage());
            }
        }

        // Description
        $description = $wppPolicy['group_description'] ?? '';
        if ($description) {
            try {
                WppGroupSetup::setDescription($tenantId, $groupId, $description);
            } catch (\Throwable $e) {
                error_log("EventSetup::setDescription failed event={$eventId}: " . $e->getMessage());
            }
        }

        // Restreindre aux admins seuls
        try {
            WppGroupSetup::setAdminsOnly($tenantId, $groupId, true);
        } catch (\Throwable $e) {
            error_log("EventSetup::setAdminsOnly failed event={$eventId}: " . $e->getMessage());
        }

        // Promouvoir les admins (ils ont été ajoutés à la création, on les promeut maintenant)
        foreach ($adminPhones as $phone) {
            try {
                WppGroupSetup::promoteToAdmin($tenantId, $groupId, $phone);
            } catch (\Throwable $e) {
                error_log("EventSetup::promoteToAdmin failed event={$eventId} phone={$phone}: " . $e->getMessage());
            }
        }

        // Sauver wa_group_id dans events
        nfg_pdo_app()
            ->prepare("UPDATE events SET wa_group_id = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$groupId, $eventId, $tenantId]);

        // Récupérer et sauvegarder le lien d'invitation
        try {
            $inviteLink = WppGroupSetup::getInviteLink($tenantId, $groupId);
            if ($inviteLink) {
                nfg_pdo_app()
                    ->prepare("UPDATE events SET wa_invite_link = ? WHERE id = ? AND tenant_id = ?")
                    ->execute([$inviteLink, $eventId, $tenantId]);
            }
        } catch (\Throwable $e) {
            error_log("EventSetup::getInviteLink failed event={$eventId}: " . $e->getMessage());
        }

        return $groupId;
    }

    // ── Brevo List ────────────────────────────────────────────────────────────

    private static function setupBrevoList(
        int   $tenantId,
        int   $eventId,
        array $event,
        array $product
    ): int {
        // Utilise start_date pour différencier les listes dans Brevo
        // (ex: "Webinaire Mensuel – 2025-05-18" vs "Webinaire Mensuel – 2025-06-15")
        $startDate = (string)($event['start_date'] ?? '');
        $result    = BrevoSetup::resolveEventList($tenantId, $product['name'], $event['label'], $startDate);
        $brevoListId = (int)$result['list_id'];

        nfg_pdo_app()
            ->prepare("UPDATE events SET brevo_list_id = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$brevoListId, $eventId, $tenantId]);

        return $brevoListId;
    }

    // ── Scheduling ────────────────────────────────────────────────────────────

    private static function scheduleAutomations(
        int    $tenantId,
        int    $eventId,
        int    $productId,
        string $waGroupId,
        int    $brevoListId
    ): void {
        // Supprimer les messages en attente pour cet événement (évite les doublons sur re-save).
        // NB : on DELETE (et non SET status='cancelled') car l'enum outbox_messages.status
        // ne contient pas 'cancelled' → un UPDATE stockerait '' (valeur invalide).
        nfg_pdo_ops()
            ->prepare("
                DELETE FROM outbox_messages
                WHERE tenant_id = ? AND type IN ('wa.event_message', 'email.campaign')
                  AND JSON_EXTRACT(payload_json, '$.event_id') = ?
                  AND status = 'pending'
            ")
            ->execute([$tenantId, $eventId]);

        // Automations event_created actives pour ce tenant+produit
        $automations = self::findEventCreatedAutomations($tenantId, $productId);
        if (!$automations) {
            // Aucun scénario event_created → étape terminée (0 message) sinon spinner éternel.
            self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => 0]]);
            return;
        }

        $tzSt = nfg_pdo_app()->prepare("SELECT tz FROM events WHERE id = ? LIMIT 1");
        $tzSt->execute([$eventId]);
        $eventTz = (string)(($tzSt->fetch(\PDO::FETCH_ASSOC)['tz'] ?? '') ?: 'UTC');

        // Sessions de l'événement
        $stSess = nfg_pdo_app()->prepare("
            SELECT session_date, start_at_utc FROM event_sessions
            WHERE event_id = ? ORDER BY session_date ASC
        ");
        $stSess->execute([$eventId]);
        $sessions = $stSess->fetchAll(\PDO::FETCH_ASSOC);
        if (!$sessions) {
            self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => 0]]);
            return;
        }

        $scheduled = 0;
        foreach ($automations as $auto) {
            $graph   = json_decode($auto['graph'] ?? '{}', true) ?: [];
            $trigger = self::findTrigger($graph);
            $steps   = self::walkGraph($graph);
            if (!$steps) continue;

            $segMode    = $trigger['event_segment_mode'] ?? 'continuous';
            $baseChoice = $trigger['base_date_choice']   ?? 'first_segment';

            $refDates = ($segMode === 'segmented' || $baseChoice === 'each_segment')
                ? array_column($sessions, 'start_at_utc')
                : [$sessions[0]['start_at_utc']];

            foreach ($refDates as $refUtc) {
                $refTs = strtotime($refUtc);

                foreach ($steps as $step) {
                    if ($step['type'] !== 'send') continue;

                    $timing = $step['timing'] ?? ['mode' => 'relative', 'offset_seconds' => $step['offset_seconds'] ?? 0];
                    if (($timing['mode'] ?? '') === 'fixed') {
                        $availTs = self::fixedTimeTs($eventTz, $refUtc, (int)($timing['days'] ?? 0), (string)($timing['time'] ?? '09:00'));
                    } else {
                        $availTs = $refTs + (int)($timing['offset_seconds'] ?? 0);
                    }
                    $availableAt = date('Y-m-d H:i:s', $availTs);
                    $idem        = substr(
                        md5("ev{$eventId}_a{$auto['id']}_{$refUtc}_{$step['node_id']}"),
                        0, 32
                    );

                    if ($step['channel'] === 'whatsapp') {
                        if (!$waGroupId) continue;
                        $body = (($step['message_mode'] ?? '') === 'direct' && trim((string)($step['body'] ?? '')) !== '')
                            ? (string)$step['body']
                            : self::templateBody($tenantId, (int)$step['template_id'], 'whatsapp');
                        if (!$body) continue;

                        Outbox::publish('wa.event_message', $tenantId, [
                            'event_id' => $eventId,
                            'group_id' => $waGroupId,
                            'body'     => $body,
                        ], $idem, null);

                        nfg_pdo_ops()
                            ->prepare("
                                UPDATE outbox_messages SET available_at = ?
                                WHERE idem_key = ? AND type = 'wa.event_message' AND status = 'pending'
                            ")
                            ->execute([$availableAt, $idem]);
                        $scheduled++;

                    } elseif ($step['channel'] === 'email') {
                        $emailPayload = ['event_id' => $eventId];
                        if (($step['message_mode'] ?? '') === 'direct'
                            && ((string)($step['subject'] ?? '') !== '' || (string)($step['body'] ?? '') !== '')) {
                            $emailPayload['message_mode'] = 'direct';
                            $emailPayload['subject']      = (string)($step['subject'] ?? '');
                            $emailPayload['body']         = (string)($step['body'] ?? '');
                            if ((string)($step['from_email'] ?? '') !== '') $emailPayload['from_email'] = (string)$step['from_email'];
                            if ((string)($step['from_name'] ?? '') !== '')  $emailPayload['from_name']  = (string)$step['from_name'];
                        } else {
                            if ((int)($step['template_id'] ?? 0) <= 0) continue;
                            $emailPayload['template_id'] = (int)$step['template_id'];
                        }
                        Outbox::publish('email.campaign', $tenantId, $emailPayload, $idem, null);

                        nfg_pdo_ops()
                            ->prepare("
                                UPDATE outbox_messages SET available_at = ?
                                WHERE idem_key = ? AND type = 'email.campaign' AND status = 'pending'
                            ")
                            ->execute([$availableAt, $idem]);
                        $scheduled++;
                    }
                }
            }
        }

        self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => $scheduled]]);
    }

    // ── Scheduling cohorte (mode per_session) via trigger 'event_session' ───────

    /**
     * Mode cohorte : pour CHAQUE session, planifie les blocs "send" des automations
     * dont le trigger est 'event_session' — relativement à la date de la session,
     * avec les variables {{session.*}} rendues. Canaux : WhatsApp (groupe) + email.
     */
    private static function schedulePerSession(int $tenantId, int $eventId, string $waGroupId, array $event): void
    {
        $productId = (int)($event['product_id'] ?? 0);

        // Dédup : retirer les messages cohorte en attente pour cet event
        nfg_pdo_ops()
            ->prepare("
                DELETE FROM outbox_messages
                WHERE tenant_id = ? AND type IN ('wa.event_message','email.campaign')
                  AND JSON_EXTRACT(payload_json, '$.event_id') = ?
                  AND status = 'pending'
            ")
            ->execute([$tenantId, $eventId]);

        $automations = self::findAutomationsByTrigger($tenantId, $productId, 'event_session');
        if (!$automations) {
            self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => 0]]);
            return;
        }

        $eventTz  = (string)($event['tz'] ?? 'UTC') ?: 'UTC';
        // Lecture PDO DIRECTE (pas nfg_query, mis en cache) → toujours les liens de
        // session à jour, y compris si run() est rappelé après une modif.
        $stSess = nfg_pdo_app()->prepare("
            SELECT id, session_date, start_at_utc, join_link, title
            FROM event_sessions WHERE event_id = ? ORDER BY session_date ASC
        ");
        $stSess->execute([$eventId]);
        $sessions = $stSess->fetchAll(\PDO::FETCH_ASSOC);
        if (!$sessions) {
            self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => 0]]);
            return;
        }

        $count = 0;
        foreach ($automations as $auto) {
            $graph = json_decode($auto['graph'] ?? '{}', true) ?: [];
            $steps = self::walkGraph($graph);
            if (!$steps) continue;

            $num = 0;
            foreach ($sessions as $sess) {
                $num++;
                $refUtc = (string)($sess['start_at_utc'] ?? '');
                $refTs  = strtotime($refUtc) ?: time();
                $vars = [
                    'session.titre'  => (string)($sess['title'] ?? ''),
                    'session.title'  => (string)($sess['title'] ?? ''),
                    'session.lien'   => (string)($sess['join_link'] ?? ''),
                    'session.link'   => (string)($sess['join_link'] ?? ''),
                    'session.numero' => (string)$num,
                    'session.number' => (string)$num,
                    'session.date'   => self::frDate((string)($sess['session_date'] ?? '')),
                ];

                foreach ($steps as $step) {
                    if (($step['type'] ?? '') !== 'send') continue;

                    $timing = $step['timing'] ?? ['mode' => 'relative', 'offset_seconds' => $step['offset_seconds'] ?? 0];
                    if (($timing['mode'] ?? '') === 'fixed') {
                        $availTs = self::fixedTimeTs($eventTz, $refUtc, (int)($timing['days'] ?? 0), (string)($timing['time'] ?? '09:00'));
                    } else {
                        $availTs = $refTs + (int)($timing['offset_seconds'] ?? 0);
                    }
                    $availableAt = date('Y-m-d H:i:s', $availTs);
                    $idem = substr(md5("evps{$eventId}_a{$auto['id']}_s{$sess['id']}_{$step['node_id']}"), 0, 32);

                    if (($step['channel'] ?? '') === 'whatsapp') {
                        if ($waGroupId === '') continue;
                        $body = (($step['message_mode'] ?? '') === 'direct' && trim((string)($step['body'] ?? '')) !== '')
                            ? (string)$step['body']
                            : self::templateBody($tenantId, (int)$step['template_id'], 'whatsapp');
                        $body = self::renderVars((string)$body, $vars);
                        if (trim($body) === '') continue;
                        Outbox::publish('wa.event_message', $tenantId, [
                            'event_id' => $eventId, 'group_id' => $waGroupId, 'body' => $body,
                        ], $idem, null);
                        nfg_pdo_ops()
                            ->prepare("UPDATE outbox_messages SET available_at = ? WHERE idem_key = ? AND type = 'wa.event_message' AND status = 'pending'")
                            ->execute([$availableAt, $idem]);
                        $count++;
                    } elseif (($step['channel'] ?? '') === 'email') {
                        $emailPayload = ['event_id' => $eventId];
                        if (($step['message_mode'] ?? '') === 'direct'
                            && ((string)($step['subject'] ?? '') !== '' || (string)($step['body'] ?? '') !== '')) {
                            $emailPayload['message_mode'] = 'direct';
                            $emailPayload['subject']      = self::renderVars((string)($step['subject'] ?? ''), $vars);
                            $emailPayload['body']         = self::renderVars((string)($step['body'] ?? ''), $vars);
                            if ((string)($step['from_email'] ?? '') !== '') $emailPayload['from_email'] = (string)$step['from_email'];
                            if ((string)($step['from_name'] ?? '') !== '')  $emailPayload['from_name']  = (string)$step['from_name'];
                        } else {
                            if ((int)($step['template_id'] ?? 0) <= 0) continue;
                            $emailPayload['template_id'] = (int)$step['template_id'];
                        }
                        Outbox::publish('email.campaign', $tenantId, $emailPayload, $idem, null);
                        nfg_pdo_ops()
                            ->prepare("UPDATE outbox_messages SET available_at = ? WHERE idem_key = ? AND type = 'email.campaign' AND status = 'pending'")
                            ->execute([$availableAt, $idem]);
                        $count++;
                    }
                }
            }
        }
        self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => $count]]);
    }

    private static function renderVars(string $s, array $vars): string
    {
        if ($s === '' || strpos($s, '{{') === false) return $s;
        $repl = [];
        foreach ($vars as $k => $v) { $repl['{{' . $k . '}}'] = $v; $repl['{{ ' . $k . ' }}'] = $v; }
        return strtr($s, $repl);
    }

    private static function frDate(string $ymd): string
    {
        if ($ymd === '') return '';
        try {
            $dt = new \DateTime($ymd);
            $m  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            return $dt->format('j') . ' ' . ($m[(int)$dt->format('n')] ?? '') . ' ' . $dt->format('Y');
        } catch (\Throwable $e) {
            return $ymd;
        }
    }

    // ── Helpers DB ────────────────────────────────────────────────────────────

    private static function loadEvent(int $tenantId, int $eventId): ?array
    {
        $st = nfg_pdo_app()->prepare("
            SELECT id, tenant_id, product_id, label, event_url, tz, time_of_day,
                   start_date, end_date, wa_group_id, wa_invite_link, brevo_list_id,
                   content_mode, cohort_config
            FROM events
            WHERE id = ? AND tenant_id = ? LIMIT 1
        ");
        $st->execute([$eventId, $tenantId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private static function loadProduct(int $tenantId, int $productId): ?array
    {
        $st = nfg_pdo_app()->prepare("
            SELECT id, name, wpp_group_name
            FROM products
            WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1
        ");
        $st->execute([$productId, $tenantId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private static function loadWppPolicy(int $productId): ?array
    {
        $st = nfg_pdo_app()->prepare("
            SELECT strategy, custom_days, base_name, group_image_url, group_description
            FROM product_wpp_policies
            WHERE product_id = ? LIMIT 1
        ");
        $st->execute([$productId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Retourne les numéros E.164 des admins principaux du tenant
     * (depuis core.user_prefs.notification_phone).
     */
    private static function loadAdminPhones(int $tenantId): array
    {
        $st = nfg_pdo_core()->prepare("
            SELECT up.notification_phone
            FROM tenant_users tu
            JOIN user_prefs up ON up.user_id = tu.user_id AND up.tenant_id = tu.tenant_id
            WHERE tu.tenant_id = ?
              AND tu.is_primary_admin = 1
              AND up.notification_phone IS NOT NULL
              AND up.notification_phone != ''
        ");
        $st->execute([$tenantId]);
        return array_values(array_filter(array_column($st->fetchAll(\PDO::FETCH_ASSOC), 'notification_phone')));
    }

    /**
     * Retourne le nom du mois en français pour une date YYYY-MM-DD.
     * Ex: "2025-06-15" → "Juin 2025"
     */
    private static function formatMonthFr(string $date): string
    {
        if (!$date) return date('m/Y');
        try {
            $dt     = new \DateTime($date);
            $months = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                       'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
            return $months[(int)$dt->format('n')] . ' ' . $dt->format('Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    private static function findEventCreatedAutomations(int $tenantId, int $productId): array
    {
        return self::findAutomationsByTrigger($tenantId, $productId, 'event_created');
    }

    /** Automations actives dont le trigger == $kind (filtrées par produit). */
    private static function findAutomationsByTrigger(int $tenantId, int $productId, string $kind): array
    {
        $st = nfg_pdo_app()->prepare("
            SELECT a.id, s.graph
            FROM automations a
            JOIN automation_snapshots s ON s.automation_id = a.id AND s.version = a.version
            WHERE a.tenant_id = ?
              AND a.status = 'active'
              AND a.flow_type = 'automation'
        ");
        $st->execute([$tenantId]);

        $result = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $graph   = json_decode($row['graph'] ?? '{}', true) ?: [];
            $trigger = self::findTrigger($graph);
            if (!$trigger) continue;
            if (($trigger['kind'] ?? '') !== $kind) continue;

            $tPid = (int)($trigger['product_id'] ?? 0);
            if ($tPid && $tPid !== $productId) continue;

            $result[] = ['id' => (int)$row['id'], 'graph' => $row['graph']];
        }
        return $result;
    }

    private static function templateBody(int $tenantId, int $templateId, string $kind): string
    {
        $st = nfg_pdo_app()->prepare("
            SELECT body FROM templates
            WHERE tenant_id = ? AND id = ? AND kind = ? AND status = 'active'
            LIMIT 1
        ");
        $st->execute([$tenantId, $templateId, $kind]);
        return (string)($st->fetchColumn() ?: '');
    }

    // ── Graph walker ──────────────────────────────────────────────────────────

    private static function findTrigger(array $graph): ?array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['data']['type'] ?? '') === 'trigger') return $node['data'];
        }
        return null;
    }

    /**
     * Parcourt le graph linéairement depuis le trigger.
     * Retourne les nœuds 'send' avec leur offset_seconds cumulé.
     */
    private static function walkGraph(array $graph): array
    {
        $nodes = [];
        foreach ($graph['nodes'] ?? [] as $n) $nodes[$n['id']] = $n;

        $edges = [];
        foreach ($graph['edges'] ?? [] as $e) $edges[$e['source']][] = $e['target'];

        $startId = null;
        foreach ($nodes as $id => $n) {
            if (($n['data']['type'] ?? '') === 'trigger') { $startId = $id; break; }
        }
        if (!$startId) return [];

        $steps        = [];
        $currentId    = $startId;
        $offsetSec    = 0;     // cumul des délais "normaux" (relatif au début de l'événement)
        $pendingEvent = null;  // dernier délai relatif/fixe à l'événement (absolu) avant un envoi
        $visited      = [];

        while ($currentId && !isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $node = $nodes[$currentId] ?? null;
            if (!$node) break;
            $data = $node['data'] ?? [];

            if (($data['type'] ?? '') === 'wait') {
                $wm = (string)($data['wait_mode'] ?? '');
                if ($wm === 'event_relative') {
                    $d = (int)($data['event_days'] ?? 0);
                    $h = (int)($data['event_hours'] ?? 0);
                    $m = (int)($data['event_minutes'] ?? 0);
                    $pendingEvent = ['mode' => 'relative', 'offset_seconds' => $d * 86400 + $h * 3600 + $m * 60];
                } elseif ($wm === 'event_fixed') {
                    $pendingEvent = [
                        'mode' => 'fixed',
                        'days' => (int)($data['event_days'] ?? 0),
                        'time' => (string)($data['event_fixed_time'] ?? '09:00'),
                    ];
                } else {
                    $unit  = $data['unit'] ?? 'days';
                    $value = (float)($data['value'] ?? $data['minutes'] ?? 0);
                    $offsetSec += (int)($value * match($unit) {
                        'minutes' => 60,
                        'hours'   => 3600,
                        default   => 86400,
                    });
                }
            } elseif (($data['type'] ?? '') === 'send') {
                $timing = $pendingEvent ?? ['mode' => 'relative', 'offset_seconds' => $offsetSec];
                $pendingEvent = null;

                $mode = (string)($data['message_mode'] ?? '');
                if ($mode !== 'direct' && $mode !== 'template') {
                    $mode = ((string)($data['template_id'] ?? '') !== '') ? 'template' : 'direct';
                }

                $steps[] = [
                    'type'           => 'send',
                    'node_id'        => $currentId,
                    'channel'        => $data['channel'] ?? '',
                    'template_id'    => $data['template_id'] ?? '',
                    'message_mode'   => $mode,
                    'subject'        => (string)($data['subject'] ?? ''),
                    'body'           => (string)($data['body'] ?? ''),
                    'from_email'     => (string)($data['from_email'] ?? ''),
                    'from_name'      => (string)($data['from_name'] ?? ''),
                    'message'        => (string)($data['message'] ?? ''),
                    'timing'         => $timing,
                    'offset_seconds' => $timing['mode'] === 'relative' ? (int)$timing['offset_seconds'] : 0,
                ];
            }

            $currentId = $edges[$currentId][0] ?? null;
        }
        return $steps;
    }

    /** Instant UTC (epoch) pour (date locale de l'événement ± $days) à $time (HH:MM) dans le fuseau de l'événement. */
    private static function fixedTimeTs(string $tz, string $refUtc, int $days, string $time): int
    {
        try {
            $dt = new \DateTime($refUtc, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($tz ?: 'UTC'));
            if ($days !== 0) $dt->modify(($days > 0 ? '+' : '-') . abs($days) . ' days');
            $parts = array_pad(explode(':', $time), 2, '0');
            $dt->setTime((int)$parts[0], (int)$parts[1], 0);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            return (int)(strtotime($refUtc) ?: time());
        }
    }
}
