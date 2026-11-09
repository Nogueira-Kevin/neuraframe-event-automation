<?php
// EventSetup.php
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
require_once __DIR__ . '/AutomationSchedule.php';
require_once __DIR__ . '/Outbox.php';

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
            // L'état est fusionné, jamais remplacé : sans remise à zéro, l'erreur d'un passage
            // précédent restait affichée à côté de phase « done ».
            self::writeState($tenantId, $eventId, ['phase' => 'running', 'error' => null]);

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
            // Non bloquante : la liste Brevo ne sert pas à la planification (les e-mails partent
            // vers les inscrits de l'événement). Une clé Brevo désactivée arrêtait toute la suite,
            // y compris les rappels WhatsApp.
            $brevoListId = (int)($event['brevo_list_id'] ?? 0);
            if (!$brevoListId) {
                try {
                    $brevoListId = self::setupBrevoList($tenantId, $eventId, $event, $product);
                } catch (\Throwable $e) {
                    error_log("EventSetup::setupBrevoList failed event={$eventId}: " . $e->getMessage());
                    self::writeState($tenantId, $eventId, ['brevo' => ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 200)]]);
                }
            }
            if ($brevoListId) {
                self::writeState($tenantId, $eventId, ['brevo' => ['status' => 'ready', 'list_id' => $brevoListId]]);
            }

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
        self::clearPendingMessages($tenantId, $eventId);

        // Automations event_created actives pour ce tenant+produit
        $automations = self::findAutomationsByTrigger($tenantId, $productId, 'event_created');
        if (!$automations) {
            // Aucun scénario event_created → étape terminée (0 message) sinon spinner éternel.
            self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => 0]]);
            return;
        }

        $tzSt = nfg_pdo_app()->prepare("SELECT tz FROM events WHERE id = ? AND tenant_id = ? LIMIT 1");
        $tzSt->execute([$eventId, $tenantId]);
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
            $trigger = AutomationSchedule::findTrigger($graph);
            $steps   = AutomationSchedule::walkGraph($graph);
            if (!$steps) continue;

            $segMode    = $trigger['event_segment_mode'] ?? 'continuous';
            $baseChoice = $trigger['base_date_choice']   ?? 'first_segment';

            $refDates = ($segMode === 'segmented' || $baseChoice === 'each_segment')
                ? array_column($sessions, 'start_at_utc')
                : [$sessions[0]['start_at_utc']];

            foreach ($refDates as $refUtc) {
                foreach ($steps as $step) {
                    $idem = substr(md5("ev{$eventId}_a{$auto['id']}_{$refUtc}_{$step['node_id']}"), 0, 32);
                    $at   = AutomationSchedule::sendAt($step['timing'], (string)$refUtc, $eventTz);
                    if (self::enqueueSend($tenantId, $eventId, $step, $waGroupId, $at, $idem)) $scheduled++;
                }
            }
        }

        self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => $scheduled]]);
    }

    /**
     * Retire les messages encore en attente de cet événement, pour qu'un ré-enregistrement
     * ne les planifie pas deux fois. Les messages déjà partis (status 'done') restent :
     * leur clé d'idempotence empêche de les renvoyer.
     * DELETE et non SET status='cancelled' : l'enum outbox_messages.status ne contient pas
     * 'cancelled', un UPDATE stockerait '' (valeur invalide).
     */
    private static function clearPendingMessages(int $tenantId, int $eventId): void
    {
        nfg_pdo_ops()
            ->prepare("
                DELETE FROM outbox_messages
                WHERE tenant_id = ? AND type IN ('wa.event_message', 'email.campaign')
                  AND JSON_EXTRACT(payload_json, '$.event_id') = ?
                  AND status = 'pending'
            ")
            ->execute([$tenantId, $eventId]);
    }

    /**
     * Met en file un nœud « send » (WhatsApp de groupe ou e-mail) pour l'instant $availableAt.
     * La date part DANS l'insertion : publier « tout de suite » puis reprogrammer laissait au
     * worker une fenêtre pour envoyer immédiatement un rappel prévu à J-7.
     * Renvoie false si l'étape n'a rien à envoyer (pas de groupe, modèle vide…).
     */
    private static function enqueueSend(
        int    $tenantId,
        int    $eventId,
        array  $step,
        string $waGroupId,
        string $availableAt,
        string $idem,
        array  $vars = []
    ): bool {
        if (($step['type'] ?? '') !== 'send') return false;
        $mode = (string)($step['message_mode'] ?? '');

        if (($step['channel'] ?? '') === 'whatsapp') {
            if ($waGroupId === '') return false;
            $body = ($mode === 'direct' && trim((string)($step['body'] ?? '')) !== '')
                ? (string)$step['body']
                : self::templateBody($tenantId, (int)$step['template_id'], 'whatsapp');
            $body = AutomationSchedule::renderVars($body, $vars);
            if (trim($body) === '') return false;

            Outbox::publish('wa.event_message', $tenantId, [
                'event_id' => $eventId,
                'group_id' => $waGroupId,
                'body'     => $body,
            ], $idem, $availableAt);
            return true;
        }

        if (($step['channel'] ?? '') === 'email') {
            $payload = ['event_id' => $eventId];
            if ($mode === 'direct'
                && ((string)($step['subject'] ?? '') !== '' || (string)($step['body'] ?? '') !== '')) {
                $payload['message_mode'] = 'direct';
                $payload['subject']      = AutomationSchedule::renderVars((string)($step['subject'] ?? ''), $vars);
                $payload['body']         = AutomationSchedule::renderVars((string)($step['body'] ?? ''), $vars);
                if ((string)($step['from_email'] ?? '') !== '') $payload['from_email'] = (string)$step['from_email'];
                if ((string)($step['from_name'] ?? '') !== '')  $payload['from_name']  = (string)$step['from_name'];
            } else {
                if ((int)($step['template_id'] ?? 0) <= 0) return false;
                $payload['template_id'] = (int)$step['template_id'];
            }
            Outbox::publish('email.campaign', $tenantId, $payload, $idem, $availableAt);
            return true;
        }

        return false;
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

        self::clearPendingMessages($tenantId, $eventId);

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
            $steps = AutomationSchedule::walkGraph($graph);
            if (!$steps) continue;

            $num = 0;
            foreach ($sessions as $sess) {
                $num++;
                $refUtc = (string)($sess['start_at_utc'] ?? '');
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
                    $idem = substr(md5("evps{$eventId}_a{$auto['id']}_s{$sess['id']}_{$step['node_id']}"), 0, 32);
                    $at   = AutomationSchedule::sendAt($step['timing'], $refUtc, $eventTz);
                    if (self::enqueueSend($tenantId, $eventId, $step, $waGroupId, $at, $idem, $vars)) $count++;
                }
            }
        }
        self::writeState($tenantId, $eventId, ['scheduling' => ['status' => 'ready', 'count' => $count]]);
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
            $trigger = AutomationSchedule::findTrigger($graph);
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
}
