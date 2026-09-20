<?php

declare(strict_types=1);

/**
 * Datenschutz-Schritt der öffentlichen Seiten (Spieler und Staff) nach dem Entsperren des persönlichen Links:
 *  - Einwilligung in die Datenschutzerklärung (bei Minderjährigen zusätzlich durch Erziehungsberechtigte) – ohne sie keine Anzeige/Änderung der Daten
 *  - Auskunft/Datenexport (Art. 15, 20) als Download
 *  - Löschung beantragen (Art. 17), landet als Anfrage im Webpanel
 */

require_once __DIR__ . '/dsgvo.php';

/**
 * Behandelt die Datenschutz-Aktionen und zeigt bei fehlender Einwilligung die Einwilligungsseite (beendet dann das Skript).
 *
 * @param array<string, mixed> $person
 */
function privacy_gate(string $entity, int $id, array $person, string $selfUrl): void
{
    $entity = dsgvo_entity($entity);
    $error = null;

    // Auskunft als Download (nur nach Entsperren, GET)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['privacy_action'] ?? '') === 'export') {
        $export = dsgvo_export($entity, $id);
        app_log('privacy.export', 'Auskunft heruntergeladen (' . $entity . ')', ['actor' => ($entity === 'staff' ? 'staff:' : 'member:') . $id, 'target_type' => $entity === 'staff' ? 'staff' : 'member', 'target_id' => $id]);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (($_GET['format'] ?? 'html') === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="meine-daten.json"');
            echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="meine-daten.html"');
            echo dsgvo_export_html($export);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['privacy_action'] ?? '');

        if ($action === 'consent') {
            verify_csrf();
            $minor = dsgvo_is_minor($entity, $person);
            $guardian = trim((string) ($_POST['guardian_name'] ?? ''));
            if (empty($_POST['consent'])) {
                $error = 'Bitte stimme der Datenschutzerklärung zu, um fortzufahren.';
            } elseif ($minor && (empty($_POST['guardian_consent']) || $guardian === '')) {
                $error = 'Bei Minderjährigen müssen die Erziehungsberechtigten zustimmen: bitte bestätigen und den Namen angeben.';
            } else {
                dsgvo_consent_record($entity, $id, $minor ? mb_substr($guardian, 0, 150) : null);
                redirect($selfUrl);
            }
        } elseif ($action === 'request_delete') {
            verify_csrf();
            if (!empty($_POST['confirm_delete'])) {
                dsgvo_request_create($entity, $id, 'loeschung', (string) ($_POST['note'] ?? ''));
                $_SESSION['privacy_notice'] = 'Deine Löschanfrage wurde übermittelt. Der Verein meldet sich innerhalb eines Monats bei dir.';
            }
            redirect($selfUrl);
        }
    }

    if (dsgvo_consent_current($entity, $id) !== null) {
        return;
    }

    // Einwilligungsseite
    $minor = dsgvo_is_minor($entity, $person);
    $org = dsgvo_settings()['org_name'];
    $pageTitle = 'Einwilligung zur Datenverarbeitung';
    require __DIR__ . '/public_header.php';
    ?>
    <div class="verify-box">
        <h1>Datenschutz und Einwilligung</h1>
        <p>Hallo <?= h((string) ($person['vorname'] ?? '')) ?>, bevor du deine Daten ansehen oder ändern kannst, bitten wir dich, die
        <a href="datenschutz.php" target="_blank" rel="noopener">Datenschutzerklärung</a> von <strong><?= h($org) ?></strong> zur Kenntnis zu nehmen.</p>
        <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
        <form method="post" action="<?= h($selfUrl) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="privacy_action" value="consent">
            <?php if (isset($_GET['token']) || isset($_POST['token'])): ?><input type="hidden" name="token" value="<?= h((string) ($_GET['token'] ?? $_POST['token'])) ?>"><?php endif; ?>
            <label style="font-weight:400;display:block;margin:12px 0;">
                <input type="checkbox" name="consent" value="1">
                Ich habe die Datenschutzerklärung gelesen und willige in die Verarbeitung meiner Daten – einschließlich Angaben zu Ernährung/Gesundheit,
                Reisepass- und Sozialversicherungsdaten – zu den dort genannten Zwecken ein. Ich kann diese Einwilligung jederzeit widerrufen.
            </label>
            <?php if ($minor): ?>
                <fieldset>
                    <legend>Erziehungsberechtigte(r)</legend>
                    <p>Da diese Person minderjährig ist, ist die Zustimmung einer erziehungsberechtigten Person erforderlich.</p>
                    <label style="font-weight:400;display:block;margin:8px 0;">
                        <input type="checkbox" name="guardian_consent" value="1">
                        Ich bin erziehungsberechtigt und stimme der Verarbeitung der Daten der oben genannten Person zu.
                    </label>
                    <label for="guardian_name">Name der/des Erziehungsberechtigten</label>
                    <input type="text" id="guardian_name" name="guardian_name" maxlength="150" value="<?= h((string) ($_POST['guardian_name'] ?? $person['erz_name'] ?? '')) ?>">
                </fieldset>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" style="margin-top:16px;">Zustimmen und fortfahren</button>
        </form>
    </div>
    <?php
    require __DIR__ . '/public_footer.php';
    exit;
}

/** Bereich „Meine Datenschutzrechte“ unterhalb des Formulars (Auskunft, Löschung, Datenschutzerklärung). */
function privacy_self_service_box(string $selfUrl): void
{
    $notice = $_SESSION['privacy_notice'] ?? null;
    unset($_SESSION['privacy_notice']);
    $sep = str_contains($selfUrl, '?') ? '&' : '?';
    ?>
    <div class="verify-box" style="margin-top:24px;">
        <h2>Meine Datenschutzrechte</h2>
        <?php if ($notice): ?><p class="alert alert-success"><?= h((string) $notice) ?></p><?php endif; ?>
        <p>Du kannst jederzeit eine Auskunft über alle gespeicherten Daten herunterladen (<a href="<?= h($selfUrl . $sep) ?>privacy_action=export&amp;format=html">Ansicht/HTML</a> ·
        <a href="<?= h($selfUrl . $sep) ?>privacy_action=export&amp;format=json">maschinenlesbar/JSON</a>) und die Löschung deiner Daten beantragen.
        Die <a href="datenschutz.php" target="_blank" rel="noopener">Datenschutzerklärung</a> findest du hier.</p>
        <form method="post" action="<?= h($selfUrl) ?>" data-confirm="Löschung wirklich beantragen? Der Verein prüft den Antrag und meldet sich bei dir.">
            <?= csrf_field() ?>
            <input type="hidden" name="privacy_action" value="request_delete">
            <?php if (isset($_GET['token'])): ?><input type="hidden" name="token" value="<?= h((string) $_GET['token']) ?>"><?php endif; ?>
            <label style="font-weight:400;display:block;margin:8px 0;"><input type="checkbox" name="confirm_delete" value="1" required> Ich möchte die Löschung meiner Daten beantragen.</label>
            <label for="privacy_note">Anmerkung (optional)</label>
            <input type="text" id="privacy_note" name="note" maxlength="500">
            <button type="submit" class="btn btn-secondary" style="margin-top:12px;">Löschung beantragen</button>
        </form>
    </div>
    <?php
}
