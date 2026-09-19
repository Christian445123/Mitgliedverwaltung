<?php

declare(strict_types=1);

/**
 * Ablauf-Erinnerungen für NADA-Zertifikat und Reisepass.
 *
 *   NADA-Zertifikat: Hinweis ab 21 Tagen (3 Wochen) vor Ablauf, danach "abgelaufen"
 *   Reisepass:       Hinweis ab 6 Monaten vor Ablauf, danach "abgelaufen"
 *
 * Geprüft werden nur aktive Mitglieder. Die Fristen lassen sich in der .env ändern
 * (EXPIRY_NADA_DAYS, EXPIRY_PASS_MONTHS). Die Prüfung läuft bei jedem Seitenaufruf, es ist also
 * kein Cronjob nötig.
 */

require_once __DIR__ . '/../db.php';

function expiry_nada_days(): int
{
    $days = (int) (getenv('EXPIRY_NADA_DAYS') ?: 21);
    return $days > 0 ? $days : 21;
}

function expiry_pass_months(): int
{
    $months = (int) (getenv('EXPIRY_PASS_MONTHS') ?: 6);
    return $months > 0 ? $months : 6;
}

/**
 * Status eines Ablaufdatums: 'expired' (abgelaufen), 'soon' (läuft bald ab) oder null (in Ordnung/leer).
 *
 * @return array{state: string, days: int, date: string}|null days < 0 = seit so vielen Tagen abgelaufen
 */
function expiry_state(?string $date, DateTimeImmutable $limit, DateTimeImmutable $today): ?array
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
    return ['state' => $days < 0 ? 'expired' : 'soon', 'days' => $days, 'date' => $expires->format('Y-m-d')];
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
        'nada' => expiry_state($row['nada_gueltig_bis'] ?? null, $today->modify('+' . expiry_nada_days() . ' days'), $today),
        'pass' => expiry_state($row['reisepass_gueltig_bis'] ?? null, $today->modify('+' . expiry_pass_months() . ' months'), $today),
    ];
}

/**
 * Alle aktiven Mitglieder mit abgelaufenem oder bald ablaufendem Dokument.
 * Sortiert: abgelaufene zuerst (am längsten überfällig zuerst), dann nach Ablaufdatum.
 *
 * @return array{nada: array<int, array<string, mixed>>, pass: array<int, array<string, mixed>>, counts: array<string, int>}
 */
function expiry_report(): array
{
    $today = new DateTimeImmutable('today');
    $nadaLimit = $today->modify('+' . expiry_nada_days() . ' days');
    $passLimit = $today->modify('+' . expiry_pass_months() . ' months');

    $report = ['nada' => [], 'pass' => [], 'counts' => ['nada_expired' => 0, 'nada_soon' => 0, 'pass_expired' => 0, 'pass_soon' => 0, 'total' => 0]];

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
        foreach (['nada' => [$row['nada_gueltig_bis'], $nadaLimit], 'pass' => [$row['reisepass_gueltig_bis'], $passLimit]] as $type => [$date, $limit]) {
            $state = expiry_state($date, $limit, $today);
            if ($state === null) {
                continue;
            }
            $report[$type][] = [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['nachname'] . ' ' . (string) $row['vorname']),
                'kader' => (string) $row['kader'],
            ] + $state;
            $report['counts'][$type . '_' . $state['state']]++;
            $report['counts']['total']++;
        }
    }

    foreach (['nada', 'pass'] as $type) {
        usort($report[$type], static fn (array $a, array $b) => $a['days'] <=> $b['days']);
    }

    return $report;
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
