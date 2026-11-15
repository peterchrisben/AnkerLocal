<?php

declare(strict_types=1);

/**
 * ANKModbus – Modbus-Zugriff über das ModBus Gateway von Symcon.
 *
 * Autor: Peter Chrisben
 *
 * Kette in Symcon: Client Socket (IP-Adresse, Port 502) → ModBus Gateway (Geräte-ID 1) → diese Instanz.
 * Das Gateway hält die Verbindung; die Instanz schickt nur Anfragen (Funktion, Adresse, Anzahl, Daten)
 * und bekommt die Antwort als Rückgabewert von SendDataToParent.
 *
 * Unterstützt: FC 03 (Holding lesen), FC 04 (Input lesen), FC 06 (ein Register schreiben),
 * FC 16 (mehrere Register schreiben). Alle Werte Big-Endian (höherwertiges Wort zuerst).
 */
trait ANKModbus
{
    /**
     * Register lesen. $function: 3 = Holding, 4 = Input. Liefert die Rohwerte (0–65535) ab Index 0.
     *
     * @return int[]
     */
    private function MbRead(int $function, int $address, int $quantity): array
    {
        $response = $this->MbSend($function, $address, $quantity, '');
        $payload = self::MbExtractPayload($response, $quantity);
        if ($payload === null) {
            throw new Exception('Unerwartete Antwort beim Lesen ab Register ' . $address . ' (' . strlen($response) . ' Bytes)');
        }
        return array_values(unpack('n*', $payload));
    }

    private function MbWriteSingle(int $address, int $value): void
    {
        $this->MbSend(6, $address, 1, pack('n', $value & 0xFFFF));
    }

    /** @param int[] $values Rohwerte je Register (0–65535) */
    private function MbWriteMultiple(int $address, array $values): void
    {
        $data = '';
        foreach ($values as $value) {
            $data .= pack('n', $value & 0xFFFF);
        }
        $this->MbSend(16, $address, count($values), $data);
    }

    /**
     * Anfrage ans Gateway. Meldungen des Gateways (Zeitüberschreitung, Ablehnung durch das Gerät)
     * kommen als PHP-Warnung – sie werden hier in eine Exception mit dem Originaltext umgewandelt.
     */
    private function MbSend(int $function, int $address, int $quantity, string $data): string
    {
        // Binärdaten müssen für den Datenfluss als UTF-8 verpackt werden (sonst scheitert json_encode)
        $json = json_encode([
            'DataID'   => '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}',
            'Function' => $function,
            'Address'  => $address,
            'Quantity' => $quantity,
            'Data'     => mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1')
        ]);
        if ($json === false) {
            throw new Exception('Anfrage konnte nicht verpackt werden');
        }
        $warning = '';
        set_error_handler(static function (int $number, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $response = $this->SendDataToParent($json);
        } catch (Throwable $e) {
            $response = false;
            $warning = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if ($warning !== '') {
            throw new Exception(trim(strip_tags($warning)));
        }
        if (!is_string($response) || (($function === 3 || $function === 4) && $response === '')) {
            throw new Exception('Keine Antwort vom Gateway');
        }
        return $response;
    }

    /**
     * Nutzdaten aus der Gateway-Antwort holen. Je nach Symcon-Version kommt die Antwort roh oder als
     * UTF-8, mit oder ohne vorangestellten Funktionscode / Byteanzahl – alles wird akzeptiert
     * (gleiches Vorgehen wie in SYMARSTEK und HuaweiPV).
     */
    private static function MbExtractPayload(string $response, int $quantity): ?string
    {
        $wanted = $quantity * 2;
        $candidates = [$response];
        if (mb_check_encoding($response, 'UTF-8')) {
            $candidates[] = mb_convert_encoding($response, 'ISO-8859-1', 'UTF-8');
        }
        foreach ($candidates as $candidate) {
            $extra = strlen($candidate) - $wanted;
            if ($extra >= 0 && $extra <= 2) {
                return substr($candidate, $extra);
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- Umrechnung

    /** @param int[] $words */
    private static function MbInt32(array $words, int $offset): int
    {
        $high = $words[$offset] ?? 0;
        if ($high >= 0x8000) {
            $high -= 0x10000;
        }
        return $high * 65536 + ($words[$offset + 1] ?? 0);
    }

    /**
     * @param int[] $words
     * @return int|float float nur auf 32-Bit-Systemen bei sehr großen Werten
     */
    private static function MbUInt32(array $words, int $offset)
    {
        return ($words[$offset] ?? 0) * 65536 + ($words[$offset + 1] ?? 0);
    }

    /** @param int[] $words */
    private static function MbString(array $words, int $offset, int $count): string
    {
        $text = '';
        for ($i = 0; $i < $count; $i++) {
            $word = $words[$offset + $i] ?? 0;
            $text .= chr(($word >> 8) & 0xFF) . chr($word & 0xFF);
        }
        $text = preg_replace('/[^\x20-\x7E]/', '', $text);
        return trim((string) $text);
    }

    /** @return int[] zwei Register (hoch, niedrig) */
    private static function MbFromInt32(int $value): array
    {
        return [($value >> 16) & 0xFFFF, $value & 0xFFFF];
    }
}
