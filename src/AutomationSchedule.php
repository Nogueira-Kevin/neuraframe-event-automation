<?php
// AutomationSchedule.php
// Logique PURE de planification (aucune base, aucun réseau) : lecture du scénario dessiné
// par l'utilisateur et calcul de l'instant d'envoi de chaque message.
// Séparée d'EventSetup pour être testée seule (tests/AutomationScheduleTest.php).
declare(strict_types=1);

final class AutomationSchedule
{
    /** Données du nœud déclencheur du graphe, ou null s'il n'y en a pas. */
    public static function findTrigger(array $graph): ?array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['data']['type'] ?? '') === 'trigger') return $node['data'];
        }
        return null;
    }

    /**
     * Parcourt le graphe linéairement depuis le déclencheur et renvoie les nœuds « send »
     * avec leur timing :
     *   - ['mode' => 'relative', 'offset_seconds' => n] : n secondes après la date de référence ;
     *   - ['mode' => 'fixed', 'days' => d, 'time' => 'HH:MM'] : à heure fixe, d jours avant/après.
     * Les attentes « normales » se cumulent ; une attente liée à l'événement s'applique
     * uniquement à l'envoi qui la suit.
     */
    public static function walkGraph(array $graph): array
    {
        $nodes = [];
        foreach ($graph['nodes'] ?? [] as $n) $nodes[$n['id']] = $n;

        $edges = [];
        foreach ($graph['edges'] ?? [] as $e) $edges[$e['source']][] = $e['target'];

        $startId = null;
        foreach ($nodes as $id => $n) {
            if (($n['data']['type'] ?? '') === 'trigger') { $startId = $id; break; }
        }
        if ($startId === null) return [];

        $steps        = [];
        $currentId    = $startId;
        $offsetSec    = 0;     // cumul des attentes « normales »
        $pendingEvent = null;  // attente liée à l'événement, consommée par le prochain envoi
        $visited      = [];    // un graphe en boucle ne doit pas bloquer le worker

        while ($currentId !== null && !isset($visited[$currentId])) {
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
                    $offsetSec += (int)($value * match ($unit) {
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

    /**
     * Instant d'envoi (DATETIME UTC, format MySQL) d'une étape, à partir de la date de
     * référence de l'événement ($refUtc, en UTC) et de son fuseau ($tz).
     * Tout est calculé explicitement en UTC : le résultat ne dépend pas du fuseau du serveur.
     */
    public static function sendAt(array $timing, string $refUtc, string $tz): string
    {
        if (($timing['mode'] ?? '') === 'fixed') {
            $ts = self::fixedTimeTs($tz, $refUtc, (int)($timing['days'] ?? 0), (string)($timing['time'] ?? '09:00'));
        } else {
            $ts = self::utcTs($refUtc) + (int)($timing['offset_seconds'] ?? 0);
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Instant UTC (epoch) de « date locale de l'événement ± $days, à $time (HH:MM) » dans le
     * fuseau de l'événement. Ex. « la veille à 9 h, heure de Genève » : l'heure d'été est
     * gérée par DateTimeZone, pas par un décalage fixe.
     */
    public static function fixedTimeTs(string $tz, string $refUtc, int $days, string $time): int
    {
        try {
            $dt = new \DateTime($refUtc, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($tz !== '' ? $tz : 'UTC'));
            if ($days !== 0) $dt->modify(($days > 0 ? '+' : '-') . abs($days) . ' days');
            $parts = array_pad(explode(':', $time), 2, '0');
            $dt->setTime((int)$parts[0], (int)$parts[1], 0);
            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            // Fuseau ou heure invalide : on envoie à la date de référence plutôt que jamais.
            return self::utcTs($refUtc);
        }
    }

    /** Remplace {{cle}} et {{ cle }} par leur valeur ; le reste du texte est inchangé. */
    public static function renderVars(string $s, array $vars): string
    {
        if ($s === '' || strpos($s, '{{') === false) return $s;
        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{{' . $k . '}}']   = $v;
            $repl['{{ ' . $k . ' }}'] = $v;
        }
        return strtr($s, $repl);
    }

    /** Epoch d'une date UTC ; repli sur maintenant si la date est illisible. */
    private static function utcTs(string $utc): int
    {
        try {
            return (new \DateTime($utc, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable $e) {
            return time();
        }
    }
}
