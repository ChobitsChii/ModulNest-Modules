<?php

declare(strict_types=1);

namespace ModulNest\Banking;

use PDO;

final class BankingRecurringRuleRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function hasNativeTables(): bool
    {
        return $this->tableExists('banking_recurring_rules')
            && $this->tableExists('banking_recurring_rule_conditions');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rulesWithConditions(int $userId, ?string $ruleType = null): array
    {
        if (!$this->hasNativeTables()) {
            return [];
        }

        $where = ['r.user_id = :user_id'];
        $params = ['user_id' => $userId];
        if ($ruleType !== null && $ruleType !== '') {
            $where[] = 'r.rule_type = :rule_type';
            $params['rule_type'] = $ruleType;
        }

        $statement = $this->pdo->prepare(
            'SELECT
                r.id,
                r.user_id,
                r.account_id,
                r.category_id,
                r.legacy_id,
                r.name,
                r.interval_type,
                r.notes,
                r.rule_type,
                r.group_label,
                r.active_from,
                r.active_to,
                r.period_mode,
                r.due_day,
                r.is_active,
                r.created_at,
                r.updated_at,
                c.id AS condition_id,
                c.legacy_id AS condition_legacy_id,
                c.field AS condition_field,
                c.operator AS condition_operator,
                c.value AS condition_value
             FROM banking_recurring_rules r
             LEFT JOIN banking_recurring_rule_conditions c
               ON c.recurring_rule_id = r.id
              AND c.user_id = r.user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(NULLIF(r.group_label, \'\'), r.name), r.name, r.id, c.id'
        );
        $statement->execute($params);

        $rules = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!isset($rules[$id])) {
                $rules[$id] = [
                    'id' => $id,
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'account_id' => $row['account_id'] !== null ? (int) $row['account_id'] : null,
                    'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
                    'legacy_id' => $row['legacy_id'] !== null ? (int) $row['legacy_id'] : null,
                    'name' => (string) ($row['name'] ?? ''),
                    'interval_type' => (string) ($row['interval_type'] ?? ''),
                    'notes' => (string) ($row['notes'] ?? ''),
                    'rule_type' => (string) ($row['rule_type'] ?? ''),
                    'group_label' => (string) ($row['group_label'] ?? ''),
                    'active_from' => $row['active_from'] !== null ? (string) $row['active_from'] : '',
                    'active_to' => $row['active_to'] !== null ? (string) $row['active_to'] : '',
                    'period_mode' => (string) ($row['period_mode'] ?? ''),
                    'due_day' => $row['due_day'] !== null ? (int) $row['due_day'] : null,
                    'is_active' => (int) ($row['is_active'] ?? 0) === 1,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'conditions' => [],
                ];
            }

            $conditionId = $row['condition_id'] !== null ? (int) $row['condition_id'] : 0;
            if ($conditionId > 0) {
                $rules[$id]['conditions'][] = [
                    'id' => $conditionId,
                    'legacy_id' => $row['condition_legacy_id'] !== null ? (int) $row['condition_legacy_id'] : null,
                    'field' => (string) ($row['condition_field'] ?? ''),
                    'operator' => (string) ($row['condition_operator'] ?? ''),
                    'value' => (string) ($row['condition_value'] ?? ''),
                ];
            }
        }

        return array_values($rules);
    }

    /**
     * @return null|array<string, mixed>
     */
    public function ruleWithConditions(int $userId, int $ruleId): ?array
    {
        foreach ($this->rulesWithConditions($userId) as $rule) {
            if ((int) ($rule['id'] ?? 0) === $ruleId) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array{
     *   id:int|null,
     *   name:string,
     *   group_label:string|null,
     *   active_from:string|null,
     *   active_to:string|null,
     *   period_mode:string,
     *   due_day:int|null,
     *   interval_type:string,
     *   rule_type:string,
     *   conditions:array<int, array{field:string,operator:string,value:string}>
     * } $data
     */
    public function saveRuleForUser(int $userId, array $data): int
    {
        if (!$this->hasNativeTables()) {
            return 0;
        }

        $ruleId = (int) ($data['id'] ?? 0);
        $this->pdo->beginTransaction();
        try {
            if ($ruleId > 0) {
                $statement = $this->pdo->prepare(
                    'UPDATE banking_recurring_rules
                     SET name = :name,
                         interval_type = :interval_type,
                         rule_type = :rule_type,
                         group_label = :group_label,
                         active_from = :active_from,
                         active_to = :active_to,
                         period_mode = :period_mode,
                         due_day = :due_day,
                         is_active = 1
                     WHERE id = :id
                       AND user_id = :user_id'
                );
                $statement->execute([
                    'id' => $ruleId,
                    'user_id' => $userId,
                    'name' => $data['name'],
                    'interval_type' => $data['interval_type'],
                    'rule_type' => $data['rule_type'],
                    'group_label' => $data['group_label'],
                    'active_from' => $data['active_from'],
                    'active_to' => $data['active_to'],
                    'period_mode' => $data['period_mode'],
                    'due_day' => $data['due_day'],
                ]);
                if ($statement->rowCount() === 0 && $this->ruleWithConditions($userId, $ruleId) === null) {
                    $this->pdo->rollBack();
                    return 0;
                }

                $delete = $this->pdo->prepare(
                    'DELETE FROM banking_recurring_rule_conditions
                     WHERE recurring_rule_id = :rule_id
                       AND user_id = :user_id'
                );
                $delete->execute(['rule_id' => $ruleId, 'user_id' => $userId]);
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO banking_recurring_rules
                        (user_id, name, interval_type, rule_type, group_label, active_from, active_to, period_mode, due_day, is_active)
                     VALUES
                        (:user_id, :name, :interval_type, :rule_type, :group_label, :active_from, :active_to, :period_mode, :due_day, 1)'
                );
                $statement->execute([
                    'user_id' => $userId,
                    'name' => $data['name'],
                    'interval_type' => $data['interval_type'],
                    'rule_type' => $data['rule_type'],
                    'group_label' => $data['group_label'],
                    'active_from' => $data['active_from'],
                    'active_to' => $data['active_to'],
                    'period_mode' => $data['period_mode'],
                    'due_day' => $data['due_day'],
                ]);
                $ruleId = (int) $this->pdo->lastInsertId();
            }

            $insertCondition = $this->pdo->prepare(
                'INSERT INTO banking_recurring_rule_conditions
                    (user_id, recurring_rule_id, field, operator, value)
                 VALUES
                    (:user_id, :rule_id, :field, :operator, :value)'
            );
            foreach ($data['conditions'] as $condition) {
                $insertCondition->execute([
                    'user_id' => $userId,
                    'rule_id' => $ruleId,
                    'field' => $condition['field'],
                    'operator' => $condition['operator'],
                    'value' => $condition['value'],
                ]);
            }

            $this->pdo->commit();

            return $ruleId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteRuleForUser(int $userId, int $ruleId): int
    {
        if ($ruleId <= 0 || !$this->hasNativeTables()) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM banking_recurring_rules
             WHERE id = :id
               AND user_id = :user_id'
        );
        $statement->execute(['id' => $ruleId, 'user_id' => $userId]);

        return $statement->rowCount();
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }
}
