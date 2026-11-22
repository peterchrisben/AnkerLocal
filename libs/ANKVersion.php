<?php

declare(strict_types=1);

/**
 * ANKVersion – Zeile mit der installierten Version für das Ende jedes Instanz-Formulars.
 *
 * Autor: Peter Chrisben
 *
 * Liest Version, Build und Datum beim Öffnen aus der installierten library.json, damit man sofort
 * sieht, ob ein Update wirklich angekommen ist.
 */
final class ANKVersion
{
    private const FALLBACK = 'AnkerLocal';

    /** z. B. „AnkerLocal 1.0 (Build 4) vom 22.11.2026“ */
    public static function Text(): string
    {
        $raw = @file_get_contents(__DIR__ . '/../library.json');
        $library = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($library)) {
            return self::FALLBACK;
        }
        $text = ($library['name'] ?? self::FALLBACK) . ' ' . ($library['version'] ?? '?');
        if (isset($library['build'])) {
            $text .= ' (Build ' . (int) $library['build'] . ')';
        }
        if (!empty($library['date'])) {
            $text .= ' vom ' . gmdate('d.m.Y', (int) $library['date']);
        }
        return $text;
    }

    /** Hängt die Versionszeile als letztes Element an die actions des Formulars. */
    public static function Append(array $form): array
    {
        $form['actions'] = array_values($form['actions'] ?? []);
        $form['actions'][] = ['type' => 'Label', 'caption' => self::Text(), 'italic' => true, 'color' => 0x808080];
        return $form;
    }
}
