<?php
declare(strict_types=1);

$hasNativeTables = (bool) ($has_native_tables ?? false);
$rules = is_array($rules ?? null) ? $rules : [];
$formData = is_array($form_data ?? null) ? $form_data : [];
$errors = is_array($errors ?? null) ? $errors : [];
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$suggestions = is_array($suggestions ?? null) ? $suggestions : null;
$suggestionLimit = (int) ($suggestion_limit ?? ($suggestions['limit'] ?? 100));
$newSuggestions = is_array($suggestions['new'] ?? null) ? $suggestions['new'] : [];
$coveredSuggestions = is_array($suggestions['covered'] ?? null) ? $suggestions['covered'] : [];
$filteredSuggestions = is_array($suggestions['filtered'] ?? null) ? $suggestions['filtered'] : [];

$fieldOptions = [
    'buchungstext' => ['label' => 'Buchungstext', 'type' => 'text'],
    'auftragskonto' => ['label' => 'Auftragskonto', 'type' => 'text'],
    'verwendungszweck' => ['label' => 'Verwendungszweck', 'type' => 'text'],
    'beguenstigter_zahlungspflichtiger' => ['label' => 'Begünstigter / Zahlungspflichtiger', 'type' => 'text'],
    'kontonummer_iban' => ['label' => 'Kontonummer / IBAN', 'type' => 'text'],
    'betrag' => ['label' => 'Betrag', 'type' => 'amount'],
];
$operatorLabels = [
    'contains' => 'enthält',
    'equals' => 'ist genau',
];
$intervalLabels = [
    'monatlich' => 'Monatlich',
    'vierteljährlich' => 'Vierteljährlich',
    'jährlich' => 'Jährlich',
];

$regularRules = array_values(array_filter($rules, static fn (array $rule): bool => (string) ($rule['rule_type'] ?? 'regel') === 'regel'));
$filterRules = array_values(array_filter($rules, static fn (array $rule): bool => (string) ($rule['rule_type'] ?? '') === 'filter'));

$groupRules = static function (array $items): array {
    $groups = [];
    foreach ($items as $rule) {
        $label = trim((string) ($rule['group_label'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($rule['name'] ?? ''));
        }
        if ($label === '') {
            $label = 'Unbenannt';
        }

        $key = mb_strtolower($label, 'UTF-8');
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $label, 'rules' => []];
        }
        $groups[$key]['rules'][] = $rule;
    }
    uasort($groups, static fn (array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));

    return array_values($groups);
};

$regularByInterval = [];
foreach ($regularRules as $rule) {
    $interval = (string) ($rule['interval_type'] ?? 'monatlich');
    $regularByInterval[$interval][] = $rule;
}
$regularGroupsByInterval = [];
foreach ($intervalLabels as $interval => $label) {
    $regularGroupsByInterval[$interval] = $groupRules($regularByInterval[$interval] ?? []);
}
$filterGroups = $groupRules($filterRules);

$defaultCondition = ['field' => 'verwendungszweck', 'operator' => 'contains', 'value' => ''];
$conditions = is_array($formData['conditions'] ?? null) && $formData['conditions'] !== [] ? $formData['conditions'] : [$defaultCondition];
$editing = (int) ($formData['id'] ?? 0) > 0;
$formOpen = $editing || $errors !== [] || trim((string) ($formData['name'] ?? '')) !== '';

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$formatDate = static function (mixed $value): string {
    $text = trim((string) $value);
    if ($text === '') {
        return 'offen';
    }
    $time = strtotime($text);
    return $time !== false ? date('d.m.Y', $time) : $text;
};
$activeRange = static function (array $rule) use ($formatDate): string {
    $from = trim((string) ($rule['active_from'] ?? ''));
    $to = trim((string) ($rule['active_to'] ?? ''));
    if ($from === '' && $to === '') {
        return 'immer';
    }
    if ($from !== '' && $to !== '') {
        return $formatDate($from) . ' bis ' . $formatDate($to);
    }
    if ($from !== '') {
        return 'ab ' . $formatDate($from);
    }
    return 'bis ' . $formatDate($to);
};
$periodLabel = static function (array $rule): string {
    $mode = (string) ($rule['period_mode'] ?? 'buchungsmonat');
    $label = $mode === 'folgemonat' ? 'Zuordnung: Folgemonat' : 'Zuordnung: Buchungsmonat';
    if (($rule['due_day'] ?? null) !== null) {
        $label .= ' · Fälligkeit: ' . (int) $rule['due_day'] . '.';
    }
    return $label;
};
$conditionFieldLabel = static function (string $field) use ($fieldOptions): string {
    if (isset($fieldOptions[$field])) {
        return $fieldOptions[$field]['label'];
    }
    return match ($field) {
        'booking_text' => 'Buchungstext',
        'purpose' => 'Verwendungszweck',
        'counterparty_name' => 'Gegenpartei',
        'counterparty_iban' => 'IBAN/Konto',
        'amount' => 'Betrag',
        default => $field,
    };
};
$formatConditionValue = static function (array $condition): string {
    $value = (string) ($condition['value'] ?? '');
    $field = (string) ($condition['field'] ?? '');
    if ($field !== 'betrag' && $field !== 'amount') {
        return $value;
    }

    $normalized = trim(str_replace([' ', "\xc2\xa0"], '', $value));
    if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } elseif (str_contains($normalized, ',')) {
        $normalized = str_replace(',', '.', $normalized);
    }
    if (!is_numeric($normalized)) {
        $normalized = $value;
    }
    $amount = (float) $normalized;
    $prefix = $amount < 0 ? '-' : '';
    return $prefix . number_format(abs($amount), 2, ',', '.') . ' €';
};
$conditionValueClass = static function (array $condition): string {
    $field = (string) ($condition['field'] ?? '');
    if ($field !== 'betrag' && $field !== 'amount') {
        return '';
    }

    $value = trim(str_replace([' ', "\xc2\xa0"], '', (string) ($condition['value'] ?? '')));
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }
    if (!is_numeric($value)) {
        return '';
    }

    return (float) $value < 0 ? 'text-danger' : 'text-success';
};
$amountClass = static function (mixed $value): string {
    $amount = (float) str_replace(',', '.', (string) $value);
    return $amount < 0 ? 'text-danger' : 'text-success';
};
$money = static function (mixed $value): string {
    $amount = (float) str_replace(',', '.', (string) $value);
    $prefix = $amount < 0 ? '-' : '';
    return $prefix . number_format(abs($amount), 2, ',', '.') . ' €';
};
$shorten = static function (mixed $value, int $length = 80): string {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    return mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length - 1, 'UTF-8') . '…' : $text;
};
$suggestionDate = static function (mixed $value): string {
    $time = strtotime((string) $value);
    return $time !== false ? date('m.Y', $time) : '';
};
?>

<?php require dirname(__DIR__) . '/partials/module-nav.php'; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Wiederkehrende Zahlungen</h1>
        <p class="text-body-secondary mb-0">Verwalte Regeln und Filter zur Erkennung wiederkehrender Umsätze.</p>
    </div>
    <?php if ($hasNativeTables): ?>
        <form method="post" action="/banking/recurring" class="d-flex flex-wrap gap-2 align-items-center">
            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
            <input type="hidden" name="action" value="detect">
            <label class="visually-hidden" for="banking-recurring-limit">Anzahl Vorschläge</label>
            <input
                type="number"
                class="form-control form-control-sm"
                id="banking-recurring-limit"
                name="limit"
                min="1"
                max="500"
                value="<?= max(1, min(500, $suggestionLimit ?: 100)) ?>"
                style="width: 7rem;"
            >
            <button type="submit" class="btn btn-outline-primary btn-sm">Vorschläge automatisch erkennen</button>
        </form>
    <?php endif; ?>
</div>

<?php if (!$hasNativeTables): ?>
    <div class="alert alert-info">Die nativen Tabellen für wiederkehrende Regeln sind noch nicht vorhanden.</div>
<?php else: ?>
    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= $esc($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= $esc($error) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $item): ?>
                    <li><?= $esc($item) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($suggestions !== null): ?>
        <section class="card shadow-sm border-0 app-card mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Erkannte Vorschläge</h2>
                        <p class="text-body-secondary small mb-0">Maximal <?= number_format($suggestionLimit, 0, ',', '.') ?> wiederkehrende Umsätze aus nativen Buchungen erkannt.</p>
                    </div>
                    <span class="badge text-bg-secondary"><?= count($newSuggestions) ?> neue Vorschläge</span>
                </div>

                <?php if ($newSuggestions === [] && $coveredSuggestions === [] && $filteredSuggestions === []): ?>
                    <p class="text-body-secondary mb-0">Aktuell konnten keine wiederkehrenden Zahlungen automatisch erkannt werden.</p>
                <?php else: ?>
                    <?php if ($newSuggestions !== []): ?>
                        <h3 class="h6 text-uppercase text-body-secondary mt-3">Neu erkannt</h3>
                        <div class="table-responsive mb-4">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Empfänger</th>
                                        <th>IBAN</th>
                                        <th>Beispiel</th>
                                        <th class="text-end">Betrag</th>
                                        <th class="text-end">Vorkommen</th>
                                        <th>Zeitraum</th>
                                        <th class="text-end">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($newSuggestions as $suggestion): ?>
                                        <?php
                                        $sample = is_array($suggestion['sample'] ?? null) ? $suggestion['sample'] : [];
                                        $sampleText = $sample['verwendungszweck'] ?? $sample['buchungstext'] ?? '';
                                        ?>
                                        <tr>
                                            <td><?= $esc($suggestion['name'] ?? '') ?></td>
                                            <td><span class="text-body-secondary small"><?= $esc($sample['kontonummer_iban'] ?? '') ?></span></td>
                                            <td><span title="<?= $esc($sampleText) ?>"><?= $esc($shorten($sampleText, 80)) ?></span></td>
                                            <td class="text-end <?= $esc($amountClass($suggestion['amount'] ?? 0)) ?>"><?= $money($suggestion['amount'] ?? 0) ?></td>
                                            <td class="text-end">
                                                <?= (int) ($suggestion['occurrences'] ?? 0) ?>×
                                                <span class="text-body-secondary">/ <?= (int) ($suggestion['distinct_months'] ?? 0) ?> Monate</span>
                                            </td>
                                            <td><?= $esc($suggestionDate($suggestion['first_seen'] ?? '')) ?> – <?= $esc($suggestionDate($suggestion['last_seen'] ?? '')) ?></td>
                                            <td class="text-end">
                                                <div class="banking-rule-actions">
                                                    <form method="post" action="/banking/recurring" class="m-0">
                                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                        <input type="hidden" name="action" value="adopt">
                                                        <input type="hidden" name="limit" value="<?= (int) $suggestionLimit ?>">
                                                        <input type="hidden" name="name" value="<?= $esc($suggestion['name'] ?? '') ?>">
                                                        <input type="hidden" name="group_label" value="<?= $esc($suggestion['name'] ?? '') ?>">
                                                        <input type="hidden" name="interval" value="monatlich">
                                                        <input type="hidden" name="rule_type" value="regel">
                                                        <?php foreach ((array) ($suggestion['conditions'] ?? []) as $condition): ?>
                                                            <input type="hidden" name="condition_field[]" value="<?= $esc($condition['field'] ?? '') ?>">
                                                            <input type="hidden" name="condition_operator[]" value="<?= $esc($condition['operator'] ?? '') ?>">
                                                            <input type="hidden" name="condition_value[]" value="<?= $esc($condition['value'] ?? '') ?>">
                                                        <?php endforeach; ?>
                                                        <button type="submit" class="btn btn-success btn-sm">Übernehmen</button>
                                                    </form>
                                                    <form method="post" action="/banking/recurring" class="m-0">
                                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                        <input type="hidden" name="action" value="filter">
                                                        <input type="hidden" name="limit" value="<?= (int) $suggestionLimit ?>">
                                                        <input type="hidden" name="name" value="<?= $esc($suggestion['name'] ?? '') ?>">
                                                        <input type="hidden" name="group_label" value="<?= $esc($suggestion['name'] ?? '') ?>">
                                                        <input type="hidden" name="interval" value="monatlich">
                                                        <input type="hidden" name="rule_type" value="filter">
                                                        <?php foreach ((array) ($suggestion['conditions'] ?? []) as $condition): ?>
                                                            <input type="hidden" name="condition_field[]" value="<?= $esc($condition['field'] ?? '') ?>">
                                                            <input type="hidden" name="condition_operator[]" value="<?= $esc($condition['operator'] ?? '') ?>">
                                                            <input type="hidden" name="condition_value[]" value="<?= $esc($condition['value'] ?? '') ?>">
                                                        <?php endforeach; ?>
                                                        <button type="submit" class="btn btn-outline-secondary btn-sm">Ausblenden</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php foreach ([['title' => 'Bereits durch Regeln abgedeckt', 'items' => $coveredSuggestions, 'badge' => 'text-bg-info'], ['title' => 'Ausgeblendete Umsätze', 'items' => $filteredSuggestions, 'badge' => 'text-bg-secondary']] as $section): ?>
                        <?php $items = is_array($section['items']) ? $section['items'] : []; ?>
                        <?php if ($items === []) continue; ?>
                        <h3 class="h6 text-uppercase text-body-secondary mt-3"><?= $esc($section['title']) ?></h3>
                        <div class="table-responsive mb-4">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Empfänger</th>
                                        <th>IBAN</th>
                                        <th>Beispiel</th>
                                        <th class="text-end">Betrag</th>
                                        <th>Regel/Filter</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $suggestion): ?>
                                        <?php
                                        $sample = is_array($suggestion['sample'] ?? null) ? $suggestion['sample'] : [];
                                        $sampleText = $sample['verwendungszweck'] ?? $sample['buchungstext'] ?? '';
                                        $matchedRule = is_array($suggestion['matched_rule'] ?? null) ? $suggestion['matched_rule'] : [];
                                        ?>
                                        <tr>
                                            <td><?= $esc($suggestion['name'] ?? '') ?></td>
                                            <td><span class="text-body-secondary small"><?= $esc($sample['kontonummer_iban'] ?? '') ?></span></td>
                                            <td><span title="<?= $esc($sampleText) ?>"><?= $esc($shorten($sampleText, 80)) ?></span></td>
                                            <td class="text-end <?= $esc($amountClass($suggestion['amount'] ?? 0)) ?>"><?= $money($suggestion['amount'] ?? 0) ?></td>
                                            <td><span class="badge <?= $esc($section['badge']) ?>"><?= $esc($shorten($matchedRule['name'] ?? '', 34)) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-5">
            <section class="card shadow-sm border-0 app-card">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <h2 class="h5 mb-0"><?= $editing ? (($formData['rule_type'] ?? 'regel') === 'filter' ? 'Filter bearbeiten' : 'Regel bearbeiten') : 'Neue Regel oder Filter anlegen' ?></h2>
                        <?php if (!$editing): ?>
                            <button
                                class="btn btn-outline-secondary btn-sm <?= $formOpen ? '' : 'collapsed' ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#banking-recurring-form-collapse"
                                aria-expanded="<?= $formOpen ? 'true' : 'false' ?>"
                                aria-controls="banking-recurring-form-collapse"
                            >
                                <span class="banking-recurring-form-show">Formular öffnen</span>
                                <span class="banking-recurring-form-hide">Formular schließen</span>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($editing): ?>
                        <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span>Bearbeitung von <strong><?= $esc($formData['name'] ?? '') ?></strong></span>
                            <a class="btn btn-sm btn-secondary" href="/banking/recurring">Abbrechen</a>
                        </div>
                    <?php endif; ?>

                    <?php if (!$editing): ?>
                        <p class="text-body-secondary small mb-3">Lege neue wiederkehrende Regeln oder Filter nur bei Bedarf an. Bestehende Regeln kannst du unten direkt bearbeiten.</p>
                    <?php endif; ?>

                    <div id="banking-recurring-form-collapse" class="collapse <?= $formOpen ? 'show' : '' ?>">
                        <form method="post" action="/banking/recurring" id="banking-recurring-form">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="rule_id" value="<?= $editing ? (int) ($formData['id'] ?? 0) : '' ?>">

                            <div class="mb-3">
                                <label for="banking-rule-name" class="form-label">Bezeichnung</label>
                                <input type="text" class="form-control" id="banking-rule-name" name="name" required value="<?= $esc($formData['name'] ?? '') ?>">
                            </div>
                        <div class="mb-3">
                            <label for="banking-rule-group" class="form-label">Gruppenname (optional)</label>
                            <input type="text" class="form-control" id="banking-rule-group" name="group_label" value="<?= $esc($formData['group_label'] ?? '') ?>">
                            <div class="form-text">Regeln mit gleichem Gruppennamen werden im Fälligkeitsstatus zusammengefasst.</div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-12 col-sm-6">
                                <label for="banking-rule-active-from" class="form-label">Aktiv von</label>
                                <input type="date" class="form-control" id="banking-rule-active-from" name="active_from" value="<?= $esc($formData['active_from'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="banking-rule-active-to" class="form-label">Aktiv bis</label>
                                <input type="date" class="form-control" id="banking-rule-active-to" name="active_to" value="<?= $esc($formData['active_to'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-12 col-md-6">
                                <label for="banking-rule-interval" class="form-label">Intervall</label>
                                <select class="form-select" id="banking-rule-interval" name="interval" required>
                                    <?php foreach ($intervalLabels as $key => $label): ?>
                                        <option value="<?= $esc($key) ?>" <?= (string) ($formData['interval_type'] ?? 'monatlich') === $key ? 'selected' : '' ?>><?= $esc($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="banking-rule-type" class="form-label">Typ</label>
                                <select class="form-select" id="banking-rule-type" name="rule_type">
                                    <option value="regel" <?= (string) ($formData['rule_type'] ?? 'regel') === 'regel' ? 'selected' : '' ?>>Aktive Regel</option>
                                    <option value="filter" <?= (string) ($formData['rule_type'] ?? 'regel') === 'filter' ? 'selected' : '' ?>>Filter</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-12 col-sm-8">
                                <label for="banking-rule-period-mode" class="form-label">Monatszuordnung</label>
                                <select class="form-select" id="banking-rule-period-mode" name="period_mode">
                                    <option value="buchungsmonat" <?= (string) ($formData['period_mode'] ?? 'buchungsmonat') === 'buchungsmonat' ? 'selected' : '' ?>>Im Buchungsmonat</option>
                                    <option value="folgemonat" <?= (string) ($formData['period_mode'] ?? 'buchungsmonat') === 'folgemonat' ? 'selected' : '' ?>>Im Folgemonat</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-4">
                                <label for="banking-rule-due-day" class="form-label">Fälligkeitstag</label>
                                <input type="number" class="form-control" id="banking-rule-due-day" name="due_day" min="1" max="31" value="<?= $esc($formData['due_day'] ?? '') ?>" placeholder="z. B. 1">
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">Bedingungen</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="banking-add-condition">+ Bedingung</button>
                            </div>
                            <div id="banking-conditions" class="d-flex flex-column gap-2">
                                <?php foreach ($conditions as $condition): ?>
                                    <div class="banking-condition-row border rounded p-2">
                                        <div class="row g-2 align-items-center">
                                            <div class="col-12 col-md-4">
                                                <select class="form-select banking-condition-field" name="condition_field[]">
                                                    <?php foreach ($fieldOptions as $key => $meta): ?>
                                                        <option value="<?= $esc($key) ?>" data-type="<?= $esc($meta['type']) ?>" <?= (string) ($condition['field'] ?? '') === $key ? 'selected' : '' ?>><?= $esc($meta['label']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-3">
                                                <select class="form-select banking-condition-operator" name="condition_operator[]">
                                                    <?php foreach ($operatorLabels as $key => $label): ?>
                                                        <option value="<?= $esc($key) ?>" <?= (string) ($condition['operator'] ?? '') === $key ? 'selected' : '' ?>><?= $esc($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <input type="text" class="form-control banking-condition-value" name="condition_value[]" value="<?= $esc($condition['value'] ?? '') ?>" placeholder="Suchmuster">
                                            </div>
                                            <div class="col-12 col-md-1 text-end">
                                                <button type="button" class="btn btn-outline-danger btn-sm banking-remove-condition" title="Bedingung entfernen">&times;</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <template id="banking-condition-template">
                                <div class="banking-condition-row border rounded p-2">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-12 col-md-4">
                                            <select class="form-select banking-condition-field" name="condition_field[]">
                                                <?php foreach ($fieldOptions as $key => $meta): ?>
                                                    <option value="<?= $esc($key) ?>" data-type="<?= $esc($meta['type']) ?>"><?= $esc($meta['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <select class="form-select banking-condition-operator" name="condition_operator[]">
                                                <?php foreach ($operatorLabels as $key => $label): ?>
                                                    <option value="<?= $esc($key) ?>"><?= $esc($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <input type="text" class="form-control banking-condition-value" name="condition_value[]" placeholder="Suchmuster">
                                        </div>
                                        <div class="col-12 col-md-1 text-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm banking-remove-condition">&times;</button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                            <div class="d-flex justify-content-end gap-2">
                                <?php if ($editing): ?>
                                    <a class="btn btn-secondary" href="/banking/recurring">Abbrechen</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary"><?= $editing ? 'Speichern' : 'Anlegen' ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-7">
            <section class="card shadow-sm border-0 app-card">
                <div class="card-body">
                    <h2 class="h5 mb-3">Überblick</h2>
                    <div class="row row-cols-1 row-cols-md-2 g-3">
                        <div class="col">
                            <p class="text-body-secondary text-uppercase small mb-1">Aktive Regeln</p>
                            <h3 class="h5 mb-0"><?= count($regularRules) ?></h3>
                            <p class="text-body-secondary small mb-0">
                                Monatlich: <?= count($regularByInterval['monatlich'] ?? []) ?> ·
                                Vierteljährlich: <?= count($regularByInterval['vierteljährlich'] ?? []) ?> ·
                                Jährlich: <?= count($regularByInterval['jährlich'] ?? []) ?>
                            </p>
                        </div>
                        <div class="col">
                            <p class="text-body-secondary text-uppercase small mb-1">Filter</p>
                            <h3 class="h5 mb-0"><?= count($filterRules) ?></h3>
                            <p class="text-body-secondary small mb-0">Filter blenden erkannte Vorschläge bzw. Auswertungen aus.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-6">
            <section class="card shadow-sm border-0 app-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Aktive Regeln</h2>
                        <span class="badge text-bg-secondary"><?= count($regularRules) ?> Regeln</span>
                    </div>
                    <?php if ($regularRules === []): ?>
                        <p class="text-body-secondary mb-0">Noch keine Regeln angelegt.</p>
                    <?php else: ?>
                        <?php foreach ($intervalLabels as $interval => $label): ?>
                            <?php $groups = $regularGroupsByInterval[$interval] ?? []; ?>
                            <?php if ($groups === []) continue; ?>
                            <h3 class="h6 text-uppercase text-body-secondary mt-3"><?= $esc($label) ?></h3>
                            <div class="table-responsive mb-3">
                                <table class="table align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Bedingungen</th>
                                            <th class="text-end">Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($groups as $group): ?>
                                            <?php $groupRules = is_array($group['rules'] ?? null) ? $group['rules'] : []; ?>
                                            <tr>
                                                <td>
                                                    <strong><?= $esc($group['label'] ?? '') ?></strong><br>
                                                    <span class="text-body-secondary small"><?= count($groupRules) ?> Variante<?= count($groupRules) === 1 ? '' : 'n' ?></span>
                                                    <?php if (count($groupRules) > 1): ?>
                                                        <br><span class="badge text-bg-primary mt-2">Regelgruppe</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php foreach ($groupRules as $index => $rule): ?>
                                                        <div class="banking-rule-variant <?= $index > 0 ? 'is-separated' : '' ?>">
                                                            <div class="small text-body-secondary mb-1">
                                                                Variante <?= $index + 1 ?> · Aktiv: <?= $esc($activeRange($rule)) ?> · <?= $esc($periodLabel($rule)) ?>
                                                            </div>
                                                            <ul class="list-unstyled small mb-0">
                                                                <?php foreach ((array) ($rule['conditions'] ?? []) as $condition): ?>
                                                                    <li>
                                                                        <span class="text-body-secondary"><?= $esc($conditionFieldLabel((string) ($condition['field'] ?? ''))) ?></span>
                                                                        <span class="text-body-secondary">|</span>
                                                                        <?= $esc($operatorLabels[(string) ($condition['operator'] ?? '')] ?? (string) ($condition['operator'] ?? '')) ?>
                                                                        <span class="text-body-secondary">|</span>
                                                                        <code class="<?= $esc($conditionValueClass($condition)) ?>"><?= $esc($formatConditionValue($condition)) ?></code>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php foreach ($groupRules as $index => $rule): ?>
                                                        <div class="banking-rule-actions <?= $index > 0 ? 'is-separated' : '' ?>">
                                                            <a class="btn btn-outline-primary btn-sm" href="/banking/recurring?edit=<?= (int) ($rule['id'] ?? 0) ?>">Bearbeiten</a>
                                                            <form method="post" action="/banking/recurring" class="m-0">
                                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="rule_id" value="<?= (int) ($rule['id'] ?? 0) ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Regel wirklich löschen?');">Löschen</button>
                                                            </form>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-6">
            <section class="card shadow-sm border-0 app-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Filterregeln</h2>
                        <span class="badge text-bg-secondary"><?= count($filterRules) ?> Filter</span>
                    </div>
                    <?php if ($filterGroups === []): ?>
                        <p class="text-body-secondary mb-0">Noch keine Filter angelegt.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Bedingungen</th>
                                        <th class="text-end">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filterGroups as $group): ?>
                                        <?php $groupRules = is_array($group['rules'] ?? null) ? $group['rules'] : []; ?>
                                        <tr>
                                            <td>
                                                <strong><?= $esc($group['label'] ?? '') ?></strong><br>
                                                <span class="text-body-secondary small"><?= count($groupRules) ?> Variante<?= count($groupRules) === 1 ? '' : 'n' ?></span>
                                                <?php if (count($groupRules) > 1): ?>
                                                    <br><span class="badge text-bg-secondary mt-2">Filtergruppe</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php foreach ($groupRules as $index => $rule): ?>
                                                    <div class="banking-rule-variant <?= $index > 0 ? 'is-separated' : '' ?>">
                                                        <div class="small text-body-secondary mb-1">
                                                            Variante <?= $index + 1 ?> · Aktiv: <?= $esc($activeRange($rule)) ?>
                                                        </div>
                                                        <ul class="list-unstyled small mb-0">
                                                            <?php foreach ((array) ($rule['conditions'] ?? []) as $condition): ?>
                                                                <li>
                                                                    <span class="text-body-secondary"><?= $esc($conditionFieldLabel((string) ($condition['field'] ?? ''))) ?></span>
                                                                    <span class="text-body-secondary">|</span>
                                                                    <?= $esc($operatorLabels[(string) ($condition['operator'] ?? '')] ?? (string) ($condition['operator'] ?? '')) ?>
                                                                    <span class="text-body-secondary">|</span>
                                                                    <code class="<?= $esc($conditionValueClass($condition)) ?>"><?= $esc($formatConditionValue($condition)) ?></code>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php foreach ($groupRules as $index => $rule): ?>
                                                    <div class="banking-rule-actions <?= $index > 0 ? 'is-separated' : '' ?>">
                                                        <a class="btn btn-outline-primary btn-sm" href="/banking/recurring?edit=<?= (int) ($rule['id'] ?? 0) ?>">Bearbeiten</a>
                                                        <form method="post" action="/banking/recurring" class="m-0">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="rule_id" value="<?= (int) ($rule['id'] ?? 0) ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Filter wirklich löschen?');">Löschen</button>
                                                        </form>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const operatorOptions = {
        text: [
            { value: 'contains', label: 'enthält' },
            { value: 'equals', label: 'ist genau' }
        ],
        amount: [
            { value: 'equals', label: 'ist genau' }
        ]
    };

    const container = document.getElementById('banking-conditions');
    const template = document.getElementById('banking-condition-template');
    const addButton = document.getElementById('banking-add-condition');
    if (!container || !template || !addButton) {
        return;
    }

    const updateOperatorSelect = function (row) {
        const field = row.querySelector('.banking-condition-field');
        const operator = row.querySelector('.banking-condition-operator');
        const value = row.querySelector('.banking-condition-value');
        if (!field || !operator || !value) {
            return;
        }

        const type = field.options[field.selectedIndex]?.dataset.type || 'text';
        const options = operatorOptions[type] || operatorOptions.text;
        const current = operator.value;
        operator.innerHTML = '';
        options.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            option.selected = item.value === current;
            operator.appendChild(option);
        });
        value.placeholder = type === 'amount' ? 'z. B. -58,00' : 'Suchmuster';
    };

    const bindRow = function (row) {
        const field = row.querySelector('.banking-condition-field');
        const remove = row.querySelector('.banking-remove-condition');
        updateOperatorSelect(row);
        field?.addEventListener('change', function () {
            updateOperatorSelect(row);
        });
        remove?.addEventListener('click', function () {
            if (container.querySelectorAll('.banking-condition-row').length > 1) {
                row.remove();
            }
        });
    };

    container.querySelectorAll('.banking-condition-row').forEach(bindRow);
    addButton.addEventListener('click', function () {
        const row = template.content.firstElementChild.cloneNode(true);
        container.appendChild(row);
        bindRow(row);
    });
});
</script>
