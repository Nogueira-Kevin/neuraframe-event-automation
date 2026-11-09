<?php
// Tests de la logique de planification — sans base de données ni réseau.
// Lancer : php tests/AutomationScheduleTest.php
declare(strict_types=1);

require __DIR__ . '/../src/AutomationSchedule.php';

$failures = 0;
$count    = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $failures, $count;
    $count++;
    if ($expected === $actual) {
        echo "  ok   {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$name}\n       attendu : " . var_export($expected, true) . "\n       obtenu  : " . var_export($actual, true) . "\n";
}

/** Construit un graphe linéaire comme l'éditeur visuel le produit. */
function graph(array ...$nodes): array
{
    $g = ['nodes' => [], 'edges' => []];
    foreach ($nodes as $i => $data) {
        $g['nodes'][] = ['id' => "n{$i}", 'data' => $data];
        if ($i > 0) $g['edges'][] = ['source' => 'n' . ($i - 1), 'target' => "n{$i}"];
    }
    return $g;
}

echo "Lecture du scénario\n";

$g = graph(
    ['type' => 'trigger', 'kind' => 'event_created'],
    ['type' => 'wait', 'unit' => 'days', 'value' => 2],
    ['type' => 'send', 'channel' => 'email', 'template_id' => '12'],
    ['type' => 'wait', 'unit' => 'hours', 'value' => 3],
    ['type' => 'send', 'channel' => 'whatsapp', 'message_mode' => 'direct', 'body' => 'Bonjour'],
    ['type' => 'wait', 'wait_mode' => 'event_fixed', 'event_days' => -1, 'event_fixed_time' => '09:00'],
    ['type' => 'send', 'channel' => 'whatsapp', 'body' => 'Rappel : demain !'],
);
$steps = AutomationSchedule::walkGraph($g);

check('trois envois trouvés', 3, count($steps));
check('1er envoi : 2 jours après', ['mode' => 'relative', 'offset_seconds' => 2 * 86400], $steps[0]['timing']);
check('1er envoi : modèle déduit du template_id', 'template', $steps[0]['message_mode']);
check('2e envoi : les attentes se cumulent (2 j + 3 h)', 2 * 86400 + 3 * 3600, $steps[1]['offset_seconds']);
check('3e envoi : heure fixe la veille', ['mode' => 'fixed', 'days' => -1, 'time' => '09:00'], $steps[2]['timing']);
check('3e envoi : sans template, message direct', 'direct', $steps[2]['message_mode']);

$loop = graph(['type' => 'trigger'], ['type' => 'send', 'channel' => 'email']);
$loop['edges'][] = ['source' => 'n1', 'target' => 'n0'];
check('un graphe en boucle ne bloque pas', 1, count(AutomationSchedule::walkGraph($loop)));
check('sans déclencheur : aucun envoi', [], AutomationSchedule::walkGraph(graph(['type' => 'send'])));
check('déclencheur trouvé', 'event_created', AutomationSchedule::findTrigger($g)['kind'] ?? null);

echo "Calcul de l'instant d'envoi\n";

check('relatif : +2 jours',
    '2026-03-22 18:00:00',
    AutomationSchedule::sendAt(['mode' => 'relative', 'offset_seconds' => 2 * 86400], '2026-03-20 18:00:00', 'Europe/Zurich'));

// Hiver à Genève (UTC+1) : la veille à 9 h locale = 8 h UTC.
check('heure fixe, hiver : la veille 9 h à Genève = 08:00 UTC',
    '2026-01-14 08:00:00',
    AutomationSchedule::sendAt(['mode' => 'fixed', 'days' => -1, 'time' => '09:00'], '2026-01-15 18:00:00', 'Europe/Zurich'));

// Passage à l'heure d'été le 29 mars 2026 : 9 h locale vaut 7 h UTC ce jour-là.
check("heure fixe, jour du passage à l'heure d'été = 07:00 UTC",
    '2026-03-29 07:00:00',
    AutomationSchedule::sendAt(['mode' => 'fixed', 'days' => -1, 'time' => '09:00'], '2026-03-30 17:00:00', 'Europe/Zurich'));

// Le résultat ne doit pas dépendre du fuseau configuré sur le serveur.
$tzServeur = date_default_timezone_get();
date_default_timezone_set('America/Sao_Paulo');
check('indépendant du fuseau du serveur',
    '2026-03-22 18:00:00',
    AutomationSchedule::sendAt(['mode' => 'relative', 'offset_seconds' => 2 * 86400], '2026-03-20 18:00:00', 'Europe/Zurich'));
date_default_timezone_set($tzServeur);

check('fuseau invalide : repli sur la date de référence',
    '2026-03-20 18:00:00',
    AutomationSchedule::sendAt(['mode' => 'fixed', 'days' => 0, 'time' => '09:00'], '2026-03-20 18:00:00', 'Mars/Olympus'));

echo "Variables des messages\n";

$vars = ['session.numero' => '3', 'session.lien' => 'https://exemple.ch/live'];
check('les deux syntaxes {{x}} et {{ x }}',
    'Session 3 : https://exemple.ch/live',
    AutomationSchedule::renderVars('Session {{session.numero}} : {{ session.lien }}', $vars));
check('une variable inconnue reste visible', 'Bonjour {{prenom}}', AutomationSchedule::renderVars('Bonjour {{prenom}}', $vars));

echo "\n" . ($count - $failures) . "/{$count} tests réussis\n";
exit($failures === 0 ? 0 : 1);
