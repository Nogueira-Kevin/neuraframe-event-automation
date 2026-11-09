<?php
// Sandbox.php
// Tenants « bac à sable » (compte de démonstration) : aucun e-mail ni message WhatsApp ne sort.
// Liste dans .env : NFG_SANDBOX_TENANTS="50" (plusieurs : "50,51").
// Les senders font comme si l envoi avait réussi et le notent dans le journal PHP : les écrans
// affichent « envoyé », la démo se déroule, mais personne ne reçoit rien.
// Pourquoi ici et pas dans les écrans : WppSender se rabat sur le WhatsApp du tenant 1 quand le
// tenant n a pas le sien — un test aurait écrit depuis le vrai numéro de la plateforme.
declare(strict_types=1);

if (!function_exists("nfg_is_sandbox_tenant")) {
    function nfg_is_sandbox_tenant(int $tenantId): bool
    {
        static $ids = null;
        if ($ids === null) {
            $raw = class_exists("Env") ? (string) Env::get("NFG_SANDBOX_TENANTS", "") : "";
            if ($raw === "") $raw = (string) getenv("NFG_SANDBOX_TENANTS");
            $ids = array_map("intval", array_filter(array_map("trim", explode(",", $raw))));
        }
        return in_array($tenantId, $ids, true);
    }

    function nfg_sandbox_log(int $tenantId, string $channel, string $to, string $subject = ""): void
    {
        error_log("[sandbox] tenant {$tenantId} : {$channel} vers {$to} NON envoyé" . ($subject !== "" ? " ({$subject})" : ""));
    }
}
