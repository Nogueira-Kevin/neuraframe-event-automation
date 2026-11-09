<?php
// public_html/global-db/_inc/Outbox.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

final class Outbox
{
  /**
   * Publica uma mensagem na fila (idempotente por idem_key).
   * @param string|null $availableAt  MySQL DATETIME — si fourni, le message ne sera
   *                                  traité qu'à partir de cette date (scheduling natif).
   *                                  null = traitement immédiat.
   */
  public static function publish(string $type, int $tenantId, array $payload, ?string $idem = null, ?string $availableAt = null): int
  {
    $pdo = nfg_pdo_ops();
    $pdo->beginTransaction();
    try {
      $id = 0;
      if ($idem) {
        $st = $pdo->prepare("SELECT id FROM outbox_messages WHERE tenant_id=? AND type=? AND idem_key=? LIMIT 1");
        $st->execute([$tenantId, $type, $idem]);
        $id = (int)($st->fetchColumn() ?: 0);
      }
      if ($id) { $pdo->commit(); return $id; }

      $st = $pdo->prepare("
        INSERT INTO outbox_messages (tenant_id, type, status, payload_json, idem_key, available_at)
        VALUES (?, ?, 'pending', ?, ?, ?)
      ");
      $st->execute([
        $tenantId,
        $type,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $idem,
        $availableAt,
      ]);

      $id = (int)$pdo->lastInsertId();
      $pdo->commit();
      return $id;
    } catch (\Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
  }

  /**
   * Reivindica um lote (status: pending -> processing),
   * bloqueando com FOR UPDATE para evitar corridas.
   */
  public static function claimBatch(string $type, int $limit = 50): array
  {
    $pdo = nfg_pdo_ops();
    $pdo->beginTransaction();
    try {
      // somente mensagens liberadas (available_at <= agora ou null)
      $stmt = $pdo->prepare("
        SELECT id
        FROM outbox_messages
        WHERE type=? AND status='pending' AND (available_at IS NULL OR available_at <= NOW())
        ORDER BY id ASC
        LIMIT {$limit}
        FOR UPDATE
      ");
      $stmt->execute([$type]);
      $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

      if (!$ids) { $pdo->commit(); return []; }

      $in  = implode(',', array_fill(0, count($ids), '?'));
      // processing_at removido → não existe na tabela
      $upd = $pdo->prepare("
        UPDATE outbox_messages
        SET status='processing', updated_at=NOW()
        WHERE id IN ($in) AND status='pending'
      ");
      $upd->execute($ids);

      $sel = $pdo->prepare("SELECT * FROM outbox_messages WHERE id IN ($in)");
      $sel->execute($ids);
      $rows = $sel->fetchAll();

      $pdo->commit();
      return $rows;
    } catch (\Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
  }

  /**
   * Annule un message planifié avant qu'il soit traité.
   * Marque status='done' pour empêcher tout traitement futur.
   * Idempotent — pas d'erreur si le message n'existe pas ou est déjà traité.
   */
  public static function cancelByIdem(string $type, int $tenantId, string $idem): void
  {
    nfg_pdo_ops()->prepare("
      UPDATE outbox_messages
         SET status='done', updated_at=NOW()
       WHERE tenant_id=? AND type=? AND idem_key=? AND status='pending'
    ")->execute([$tenantId, $type, $idem]);
  }

  public static function markDone(int $id): void
  {
    $pdo = nfg_pdo_ops();
    // done_at removido → não existe na tabela
    $pdo->prepare("
      UPDATE outbox_messages
      SET status='done', updated_at=NOW()
      WHERE id=?
    ")->execute([$id]);
  }

  /**
   * Registra erro e aplica backoff exponencial (1m, 2m, 4m, 8m, 16m...).
   * Quando tenta >= $maxAttempts -> DLQ.
   */
  public static function markError(int $id, string $err, int $maxAttempts = 6): void
  {
    $pdo = nfg_pdo_ops();

    // lê estado atual
    $st = $pdo->prepare("
      SELECT attempts, tenant_id, type, payload_json
      FROM outbox_messages
      WHERE id=?
    ");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return;

    $attempts = (int)$row['attempts'] + 1;

    if ($attempts >= $maxAttempts) {
      self::moveToDeadLetter($id, $err);
      return;
    }

    // backoff: 60 * 2^(attempts-1)
    $delaySec = 60 * (2 ** ($attempts - 1));
    $pdo->prepare("
      UPDATE outbox_messages
      SET status='pending',
          attempts=?,
          last_error=?,
          available_at=DATE_ADD(NOW(), INTERVAL ? SECOND),
          updated_at=NOW()
      WHERE id=?
    ")->execute([
      $attempts,
      mb_strimwidth($err, 0, 1000, '...'),
      $delaySec,
      $id
    ]);
  }

  public static function moveToDeadLetter(int $id, string $err): void
  {
    $pdo = nfg_pdo_ops();
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("SELECT * FROM outbox_messages WHERE id=? FOR UPDATE");
      $st->execute([$id]);
      $msg = $st->fetch();
      if ($msg) {
        $pdo->prepare("
          INSERT INTO outbox_dead_letter (msg_id, tenant_id, type, payload_json, error_msg, created_at)
          VALUES (?,?,?,?,?, NOW())
        ")->execute([
          (int)$msg['id'],
          (int)$msg['tenant_id'],
          (string)$msg['type'],
          (string)$msg['payload_json'],
          mb_strimwidth($err, 0, 2000, '...')
        ]);
        $pdo->prepare("
          UPDATE outbox_messages
          SET status='error', updated_at=NOW()
          WHERE id=?
        ")->execute([$id]);
      }
      $pdo->commit();
    } catch (\Throwable $e) {
      $pdo->rollBack();
      // mantém como está; o worker vai tentar de novo
    }
  }
}
