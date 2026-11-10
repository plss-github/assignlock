<?php

namespace GlpiPlugin\Assignlock;

use Log;
use Ticket;

/**
 * Entrada na aba "Histórico" do chamado quando uma atribuição é barrada.
 * `getTypeName()` e `getHistoryEntry()` são o contrato do GLPI para entradas de plugin em glpi_logs.
 */
final class History
{
    public const ACTION = Log::HISTORY_PLUGIN + 1;

    public static function getTypeName($nb = 0): string
    {
        return __('Trava de atribuição', 'assignlock');
    }

    public static function add(int $tickets_id, string $text): void
    {
        Log::history($tickets_id, Ticket::class, [0, '', $text], self::class, self::ACTION);
    }

    /** @param array<string, mixed> $data linha de glpi_logs */
    public static function getHistoryEntry(array $data): string
    {
        return htmlescape((string) ($data['new_value'] ?? ''));
    }
}
