<?php

declare(strict_types=1);

/**
 * Ablauf-Erinnerungen für NADA-Zertifikat und Reisepass.
 *
 *   NADA-Zertifikat (Ablaufdatum "nada_gueltig_bis"):
 *     ab 1 Monat vorher   -> GELB   (Stufe "notice")
 *     ab 7 Tage vorher    -> BLAU   (Stufe "urgent")
 *     am Ablauftag und danach -> ROT (Stufe "expired")
 *   Reisepass (Ablaufdatum "reisepass_gueltig_bis"):
 *     ab 6 Monate vorher  -> ORANGE (Stufe "soon"), abgelaufen (nach dem Ablauftag) -> ROT
 *
 * Geprüft werden nur aktive Mitglieder. Die Fristen lassen sich in der .env ändern
 * (EXPIRY_NADA_MONTHS, EXPIRY_NADA_URGENT_DAYS, EXPIRY_PASS_MONTHS). Die Prüfung läuft bei jedem
 * Seitenaufruf, es ist also kein Cronjob nötig.
 */

require_once __DIR__ . '/../db.php';

function expiry_nada_months(): int
{
    $months = (int) (getenv('EXPIRY_NADA_MONTHS') ?: 1);
    return $months > 0 ? $months : 1;
}

function expiry_nada_urgent_days(): int
{
    $days = (int) (getenv('EXPIRY_NADA_URGENT_DAYS') ?: 7);
    return $days > 0 ? $days : 7;
}

function expiry_pass_months(): int
{
    $months = (int) (getenv('EXPIRY_PASS_MONTHS') ?: 6);
    return $months > 0 ? $months : 6;
}

/**
 * Status eines Ablaufdatums oder null (in Ordnung / kein Datum / noch außerhalb der Frist).
 *
 * Stufen: 'expired' (rot), 'urgent' (blau), 'notice' (gelb), 'soon' (orange).
 *
 * @param int|null $urgentDays  wenn gesetzt: dreistufig (notice -> urgent -> expired) wie beim NADA-Zertifikat
 * @param bool $redOnDay        true = schon am Ablauftag selbst "expired"
 * @return array{state: string, days: int, date: string}|null days < 0 = seit so vielen Tagen abgelaufen
 */
function expiry_state(?string $date, DateTimeImmutable $limit, DateTimeImmutable $today, ?int $urgentDays = null, bool $redOnDay = false): ?array
{
    if ($date === null || $date === '') {
        return null;
    }
    try {
        $expires = new DateTimeImmutable($date);
    } catch (Throwable $e) {
        return null;
    }
    $expires = $expires->setTime(0, 0);
    if ($expires > $limit) {
        return null;
    }

    $days = (int) $today->diff($expires)->format('%r%a');
    if ($days < 0 || ($redOnDay && $days === 0)) {
        $state = 'expired';
    } elseif ($urgentDays !== null) {
        $state = $days <= $urgentDays ? 'urgent' : 'notice';
    } else {
        $state = 'soon';
    }
    return ['state' => $state, 'days' => $days, 'date' => $expires->format('Y-m-d')];
}

/** @return array<string, mixed>|null */
function expiry_nada_state(?string $date, ?DateTimeImmutable $today = null): ?array
{
    $today ??= new DateTimeImmutable('today');
    return expiry_state($date, $today->modify('+' . expiry_nada_months() . ' months'), $today, expiry_nada_urgent_days(), true);
}

/** @return array<string, mixed>|null */
function expiry_pass_state(?string $date, ?DateTimeImmutable $today = null): ?array
{
    $today ??= new DateTimeImmutable('today');
    return expiry_state($date, $today->modify('+' . expiry_pass_months() . ' months'), $today);
}

/**
 * Status beider Dokumente einer Mitglieds-Zeile (für Markierungen in der Liste).
 *
 * @param array<string, mixed> $row benötigt nada_gueltig_bis und reisepass_gueltig_bis
 * @return array{nada: array<string, mixed>|null, pass: array<string, mixed>|null}
 */
function expiry_states_for_row(array $row): array
{
    $today = new DateTimeImmutable('today');
    return [
        'nada' => expiry_nada_state($row['nada_gueltig_bis'] ?? null, $today),
        'pass' => expiry_pass_state($row['reisepass_gueltig_bis'] ?? null, $today),
    ];
}

/**
 * Alle aktiven Mitglieder mit abgelaufenem oder bald ablaufendem Dokument.
 * Sortiert nach Dringlichkeit (überfällige zuerst), dann nach Ablaufdatum.
 *
 * @return array{nada: array<int, array<string, mixed>>, pass: array<int, array<string, mixed>>, counts: array<string, int>}
 */
function expiry_report(): array
{
    $today = new DateTimeImmutable('today');
    $nadaLimit = $today->modify('+' . expiry_nada_months() . ' months');
    $passLimit = $today->modify('+' . expiry_pass_months() . ' months');

    $report = ['nada' => [], 'pass' => [], 'counts' => ['total' => 0, 'expired' => 0]];

    try {
        $stmt = db()->prepare(
            "SELECT m.id, m.nachname, m.vorname, m.kader, d.nada_gueltig_bis, d.reisepass_gueltig_bis
             FROM members m JOIN member_documents d ON d.member_id = m.id
             WHERE m.status = 'aktiv'
               AND ((d.nada_gueltig_bis IS NOT NULL AND d.nada_gueltig_bis <= ?)
                 OR (d.reisepass_gueltig_bis IS NOT NULL AND d.reisepass_gueltig_bis <= ?))"
        );
        $stmt->execute([$nadaLimit->format('Y-m-d'), $passLimit->format('Y-m-d')]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('expiry_report: ' . $e->getMessage());
        return $report;
    }

    foreach ($rows as $row) {
        $states = [
            'nada' => expiry_nada_state($row['nada_gueltig_bis'], $today),
            'pass' => expiry_pass_state($row['reisepass_gueltig_bis'], $today),
        ];
        foreach ($states as $type => $state) {
            if ($state === null) {
                continue;
            }
            $report[$type][] = [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['nachname'] . ' ' . (string) $row['vorname']),
                'kader' => (string) $row['kader'],
            ] + $state;
            $key = $type . '_' . $state['state'];
            $report['counts'][$key] = ($report['counts'][$key] ?? 0) + 1;
            $report['counts']['total']++;
            if ($state['state'] === 'expired') {
                $report['counts']['expired']++;
            }
        }
    }

    foreach (['nada', 'pass'] as $type) {
        usort($report[$type], static fn (array $a, array $b) => $a['days'] <=> $b['days']);
    }

    return $report;
}

/** CSS-Farbe (badge-…) je Stufe: rot / blau / gelb / orange. */
function expiry_badge_class(string $state): string
{
    return match ($state) {
        'expired' => 'red',
        'urgent' => 'blue',
        'notice' => 'yellow',
        default => 'orange',
    };
}

/** Kurzer Text im Etikett. */
function expiry_badge_label(array $item): string
{
    if ($item['state'] === 'expired') {
        return (int) $item['days'] === 0 ? 'heute' : 'abgelaufen';
    }
    return match ($item['state']) {
        'urgent' => 'in ' . (int) $item['days'] . ' Tg.',
        'notice' => 'bald',
        default => 'bald',
    };
}

/** "abgelaufen seit 12 Tagen" / "läuft in 5 Tagen ab" / "läuft heute ab" */
function expiry_text(array $item): string
{
    $days = (int) $item['days'];
    if ($days < 0) {
        $n = abs($days);
        return 'abgelaufen seit ' . $n . ($n === 1 ? ' Tag' : ' Tagen');
    }
    if ($days === 0) {
        return 'läuft heute ab';
    }
    if ($days > 60) {
        return 'läuft in ca. ' . (int) round($days / 30) . ' Monaten ab';
    }
    return 'läuft in ' . $days . ($days === 1 ? ' Tag' : ' Tagen') . ' ab';
}
