# Neuraframe — automatisation des événements

[![tests](https://github.com/Nogueira-Kevin/neuraframe-event-automation/actions/workflows/tests.yml/badge.svg)](https://github.com/Nogueira-Kevin/neuraframe-event-automation/actions/workflows/tests.yml)

> **En bref.** Préparer un événement prenait jusqu'à 3 jours à la main. J'ai automatisé
> toute la préparation : elle se fait maintenant en quelques secondes, en production.
> Ce dépôt en montre le cœur, un bug trouvé en revue de code et corrigé, et 16 tests.

| | |
|---|---|
| **Situation** | Formateurs et organisateurs utilisent [Neuraframe](https://neuraframe.ch), ma plateforme SaaS multi-tenant (PHP 8, MySQL). |
| **Problème** | Pour chaque événement : créer le groupe WhatsApp (icône, description, admins), la liste e-mail, puis programmer chaque rappel (J-7, J-1, jour J…). Jusqu'à 3 jours de travail. |
| **Ce que j'ai fait** | Une préparation automatique déclenchée à l'enregistrement, fiable même si elle est lancée deux fois, qui suit le scénario dessiné par l'utilisateur dans un éditeur visuel. |
| **Résultat** | Quelques secondes au lieu de jours. Les rappels sont calculés à l'heure locale de l'événement, heure d'été comprise. |

## Comment ça marche

Quand un événement est enregistré, un job `event.setup` est mis en file d'attente.
Le worker appelle `EventSetup::run()`, qui fait tout en **quelques secondes** :

```mermaid
flowchart LR
    A[Événement enregistré] --> B[(File outbox)]
    B --> C[EventSetup::run]
    C --> D[Groupe WhatsApp<br/>icône, description, admins]
    C --> E[Liste e-mail Brevo]
    C --> F[Planification des messages<br/>relative aux dates]
    F --> B
    B --> G[Senders<br/>e-mail · WhatsApp]
```

## Ce que montre ce dépôt

| Fichier | Rôle |
|---|---|
| [`src/AutomationSchedule.php`](src/AutomationSchedule.php) | Logique pure, testée seule : lecture du scénario dessiné dans l'éditeur visuel et calcul de l'instant d'envoi de chaque message (« la veille à 9 h, heure de Genève », heure d'été comprise), indépendamment du fuseau du serveur. |
| [`src/EventSetup.php`](src/EventSetup.php) | Orchestrateur : verrou MySQL `GET_LOCK` contre les exécutions en double (bouton + cron), étapes idempotentes (on ne recrée pas un groupe ou une liste qui existe), état de progression écrit en base pour l'interface, chaque message mis en file directement avec sa date d'envoi. |
| [`src/Outbox.php`](src/Outbox.php) | File de messages asynchrone : verrouillage `FOR UPDATE` contre les doubles traitements, clé d'idempotence, reprise avec backoff exponentiel, puis file des échecs (DLQ). |
| [`src/senders/WppSender.php`](src/senders/WppSender.php) | Envoi WhatsApp via Evolution API, avec repli sur le numéro de la plateforme si le tenant n'a pas connecté le sien. |
| [`src/Sandbox.php`](src/Sandbox.php) | Mode « bac à sable » pour les comptes de démonstration : aucun e-mail ni message ne sort, mais l'interface se comporte normalement. |

## Sécurité

- Isolation multi-tenant : l'événement, le produit et les modèles sont chargés avec le `tenant_id` du client.
- Secrets des intégrations (Stripe, Postal) chiffrés en base (AES-256-GCM) et **jamais
  renvoyés en clair au navigateur**, même à un administrateur : uniquement une forme masquée.
- Comptes de démonstration isolés par le bac à sable, placé *avant* le repli WhatsApp —
  sinon un test aurait écrit depuis le vrai numéro de la plateforme.

## Lancer les tests

Aucune base de données ni dépendance : PHP 8.2 ou plus suffit.

```bash
php tests/AutomationScheduleTest.php
```

Les tests couvrent la lecture du scénario (attentes cumulées, boucles, heure fixe) et le
calcul des dates, dont le jour du passage à l'heure d'été. Ils tournent aussi à chaque
envoi sur GitHub (PHP 8.2 et 8.3).

## À savoir

- C'est un **extrait** : il dépend d'autres briques de la plateforme (connexion aux bases,
  création des groupes, listes Brevo) qui restent privées. L'orchestrateur n'est donc pas exécutable seul ; la logique de planification, elle, l'est (voir les tests).
- Le code a été écrit avec l'aide d'assistants IA (Claude, Codex). La conception, les choix
  d'architecture, l'intégration, les tests et l'exploitation en production sont les miens.

## Auteur

Kevin Nogueira — Suisse · [LinkedIn](https://www.linkedin.com/in/kevin-nogueira)
