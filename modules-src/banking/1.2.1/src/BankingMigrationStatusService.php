<?php

declare(strict_types=1);

namespace ModulNest\Banking;

final class BankingMigrationStatusService
{
    /**
     * @return array<string, string>
     */
    public function status(): array
    {
        return [
            'phase' => 'Native Modulhülle angelegt',
            'data_migration' => 'Noch nicht ausgeführt',
            'schema_migration' => 'Noch nicht ausgeführt',
            'next_step' => 'Schema und Dry-Run-Import erst nach Freigabe der Migrationsplanung.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function legacyStatus(): array
    {
        return [
            'path' => 'app/Legacy/banking.old',
            'mode' => 'Read-only Archiv und Referenz',
            'rule' => 'Keine funktionalen Änderungen im Legacy-Pfad.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function plannedTables(): array
    {
        return [
            'banking_migration_runs',
            'banking_accounts',
            'banking_categories',
            'banking_import_batches',
            'banking_transactions',
            'banking_recurring_rules',
            'banking_recurring_rule_conditions',
        ];
    }
}
