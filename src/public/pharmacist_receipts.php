<?php
require_once __DIR__ . '/auth.php';
$user = require_role('pharmacist');
global $pdo;

$pharmacistId = (int)$user['role_id'];

// 1. Клиники, где работает фармацевт
$stmt = $pdo->prepare("
    SELECT c.id, c.full_name, c.short_name
    FROM pharmacist_clinics pc
    JOIN clinics c ON c.id = pc.clinic_id
    WHERE pc.pharmacist_id = ?
    ORDER BY c.full_name
");
$stmt->execute([$pharmacistId]);
$clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clinicId = (int)($_REQUEST['clinic_id'] ?? ($clinics[0]['id'] ?? 0));

$error = null;

// 2. Обработка формы: ОДНА накладная с МНОГО лекарств
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clinicId = (int)($_POST['clinic_id'] ?? 0);

    $drugIds = $_POST['drug_id'] ?? [];
    $formIds = $_POST['form_id'] ?? [];
    $qtys    = $_POST['quantity'] ?? [];

    // соберём валидные позиции
    $items = [];
    if (is_array($drugIds) && is_array($formIds) && is_array($qtys)) {
        $count = max(count($drugIds), count($formIds), count($qtys));
        for ($i = 0; $i < $count; $i++) {
            $d = (int)($drugIds[$i] ?? 0);
            $f = (int)($formIds[$i] ?? 0);
            $q = (int)($qtys[$i]    ?? 0);
            if ($d > 0 && $f > 0 && $q > 0) {
                $items[] = [
                        'drug_id' => $d,
                        'form_id' => $f,
                        'qty'     => $q,
                ];
            }
        }
    }

    if ($clinicId <= 0 || empty($items)) {
        $error = 'Выберите аптеку и добавьте хотя бы одну строку с лекарством и количеством.';
    } else {
        // проверка, что фармацевт привязан к этой аптеке
        $st = $pdo->prepare("
            SELECT 1
            FROM pharmacist_clinics
            WHERE pharmacist_id = ? AND clinic_id = ?
        ");
        $st->execute([$pharmacistId, $clinicId]);
        if (!$st->fetchColumn()) {
            $error = 'У вас нет доступа к этой аптеке.';
        } else {
            try {
                $pdo->beginTransaction();

                $now = date('Y-m-d H:i:s');

                // шапка накладной
                $st = $pdo->prepare("
                    INSERT INTO receipts (clinic_id, pharmacist_id, received_at)
                    VALUES (?, ?, ?)
                ");
                $st->execute([$clinicId, $pharmacistId, $now]);
                $receiptId = (int)$pdo->lastInsertId();

                // подготовка запросов для проверки и вставки строк
                $checkDf = $pdo->prepare("
                    SELECT 1
                    FROM drug_forms
                    WHERE drug_id = ? AND form_id = ?
                ");
                $insItem = $pdo->prepare("
                    INSERT INTO receipt_items (receipt_id, drug_id, form_id, quantity)
                    VALUES (?, ?, ?, ?)
                ");

                foreach ($items as $it) {
                    $checkDf->execute([$it['drug_id'], $it['form_id']]);
                    if (!$checkDf->fetchColumn()) {
                        throw new RuntimeException(
                                'Форма не разрешена для лекарства (drug_id=' .
                                $it['drug_id'] . ', form_id=' . $it['form_id'] . ')'
                        );
                    }
                    $insItem->execute([$receiptId, $it['drug_id'], $it['form_id'], $it['qty']]);
                }

                $pdo->commit();

                header('Location: pharmacist_receipts.php?clinic_id=' . $clinicId);
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Ошибка при сохранении накладной: ' . $e->getMessage();
            }
        }
    }
}

// 3. Справочники

// все лекарства
$drugs = $pdo->query("
    SELECT id, name
    FROM drugs
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// формы из drug_forms (какие формы разрешены для каждого лекарства)
$rows = $pdo->query("
    SELECT df.drug_id, f.id AS form_id, f.form_name
    FROM drug_forms df
    JOIN forms f ON f.id = df.form_id
    ORDER BY df.drug_id, f.form_name
")->fetchAll(PDO::FETCH_ASSOC);

// соберём в массив вида [drug_id => [ ['id'=>..,'form_name'=>..], ... ]]
$formsByDrug = [];
foreach ($rows as $row) {
    $dId = (int)$row['drug_id'];
    if (!isset($formsByDrug[$dId])) {
        $formsByDrug[$dId] = [];
    }
    $formsByDrug[$dId][] = [
            'id'        => (int)$row['form_id'],
            'form_name' => $row['form_name'],
    ];
}

// список лекарств для поиска в JS
$drugList = [];
foreach ($drugs as $d) {
    $drugList[] = [
            'id'   => (int)$d['id'],
            'name' => $d['name'],
    ];
}

// 4. Список накладных для выбранной аптеки
$receipts = [];
if ($clinicId > 0) {
    $stmt = $pdo->prepare("
        SELECT r.*, u.full_name AS pharmacist_name
        FROM receipts r
        JOIN pharmacists p ON p.id = r.pharmacist_id
        JOIN users u      ON u.id = p.id_user
        WHERE r.clinic_id = ?
        ORDER BY r.received_at DESC, r.id DESC
        LIMIT 30
    ");
    $stmt->execute([$clinicId]);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// запрос для позиций
$stmtItems = $pdo->prepare("
    SELECT d.name AS drug_name, f.form_name, ri.quantity
    FROM receipt_items ri
    JOIN drugs d ON d.id = ri.drug_id
    JOIN forms f ON f.id = ri.form_id
    WHERE ri.receipt_id = ?
    ORDER BY d.name, f.form_name
");

include __DIR__ . '/header.php';
?>

<div class="container">
    <h1>Накладные (приход лекарств)</h1>

    <?php if (empty($clinics)): ?>
        <div class="card">
            <div class="cardHeader">Нет привязанных аптек</div>
            <div class="muted">
                Для этого фармацевта не привязано ни одной аптеки (таблица <code>pharmacist_clinics</code>).
            </div>
        </div>
    <?php else: ?>

        <!-- выбор аптеки -->
        <form method="get" class="pharmToolbar" style="margin-bottom: 16px;">
            <div class="pharmField">
                <label>Аптека</label>
                <select name="clinic_id">
                    <?php foreach ($clinics as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $clinicId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['short_name'] ?? $c['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="herb-btn herb-btn-outline" style="height:42px;">Выбрать</button>
        </form>

        <?php if ($error): ?>
            <div class="card" style="border-color:#fca5a5;">
                <div class="cardHeader">Ошибка</div>
                <div class="muted"><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- форма создания НОВОЙ накладной с несколькими препаратами -->
        <div class="card" style="margin-top:15px;">
            <div class="cardHeader">Новая накладная</div>

            <form method="post" id="receiptForm">
                <input type="hidden" name="clinic_id" value="<?= (int)$clinicId ?>">

                <div class="muted" style="margin-bottom:8px;">
                    В одной накладной можно указать сразу несколько лекарств.
                </div>

                <table class="table" id="itemsTable" style="width:100%; border-collapse:separate; border-spacing:0 6px;">
                    <!-- ВАЖНО: контролируем ширину колонок -->
                    <colgroup>
                        <col style="width:260px;">   <!-- Лекарство -->
                        <col style="width:220px;">   <!-- Форма -->
                        <col style="width:140px;">   <!-- Количество -->
                        <col style="width:60px;">    <!-- Кнопка -->
                    </colgroup>
                    <thead>
                    <tr>
                        <th style="text-align:left;">Лекарство</th>
                        <th style="text-align:left;">Форма</th>
                        <th style="text-align:left;">Количество</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <!-- первая строка-шаблон -->
                    <tr class="itemRow">
                        <td>
                            <!-- КАСТОМНЫЙ "селект" с поиском, ширину даёт колоночка 260px -->
                            <div class="drug-select-wrapper" style="position:relative; width:100%;">
                                <input type="text"
                                       class="drug-input"
                                       placeholder="Начните вводить название..."
                                       autocomplete="off"
                                       style="width:100%; padding:6px 8px; border-radius:8px; border:1px solid #e6e8f0; box-sizing:border-box;">
                                <input type="hidden" name="drug_id[]" class="drug-id-input">
                                <div class="drug-dropdown"
                                     style="position:absolute; left:0; right:0; top:100%; background:#fff; border:1px solid #e6e8f0; border-radius:8px; max-height:220px; overflow-y:auto; display:none; z-index:10;"></div>
                            </div>
                        </td>
                        <td>
                            <select name="form_id[]" class="form-select" required
                                    style="width:100%; padding:6px 8px; border-radius:8px; border:1px solid #e6e8f0; box-sizing:border-box;">
                                <option value="">— выберите лекарство —</option>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="quantity[]" min="1" required
                                   style="width:100%; padding:6px 8px; border-radius:8px; border:1px solid #e6e8f0; box-sizing:border-box;">
                        </td>
                        <td style="text-align:right;">
                            <button type="button" class="btn btn-secondary removeRowBtn">−</button>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <button type="button" class="btn btn-secondary" id="addRowBtn" style="margin-top:8px;">
                    + Добавить лекарство
                </button>

                <div style="margin-top:16px;">
                    <button class="btn btn-primary" style="height:42px;">Сохранить накладную</button>
                </div>
            </form>
        </div>

        <!-- список накладных -->
        <?php if (empty($receipts)): ?>
            <div class="card" style="margin-top:15px;">
                <div class="cardHeader">Накладных пока нет</div>
                <div class="muted">Для выбранной аптеки ещё не зарегистрировано ни одной поставки.</div>
            </div>
        <?php else: ?>
            <?php foreach ($receipts as $r): ?>
                <div class="card" style="margin-top:15px;">
                    <div class="cardHeader">
                        Накладная №<?= (int)$r['id'] ?> —
                        <?= htmlspecialchars(date('d.m.Y H:i', strtotime($r['received_at']))) ?>
                    </div>
                    <div class="muted" style="margin-bottom:8px;">
                        Принял: <?= htmlspecialchars($r['pharmacist_name']) ?>
                    </div>

                    <?php
                    $stmtItems->execute([$r['id']]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (!empty($items)): ?>
                        <ul style="margin: 0 0 0 18px;">
                            <?php foreach ($items as $it): ?>
                                <li>
                                    <?= htmlspecialchars($it['drug_name']) ?>
                                    (<?= htmlspecialchars($it['form_name']) ?>) —
                                    <?= (int)$it['quantity'] ?> шт.
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="muted">Позиции не найдены.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
    // формы по лекарству: { drug_id: [ {id, form_name}, ... ] }
    const formsByDrug = <?= json_encode($formsByDrug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    // лекарства: [{id, name}, ...]
    const drugs = <?= json_encode($drugList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const itemsTable = document.getElementById('itemsTable').querySelector('tbody');
    const addRowBtn  = document.getElementById('addRowBtn');

    function esc(str) {
        return String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    function updateFormsForRow(row, drugId) {
        const formSelect = row.querySelector('.form-select');
        const list = formsByDrug[drugId] || [];

        if (!list.length) {
            formSelect.innerHTML = '<option value="">— для этого лекарства нет форм —</option>';
            return;
        }

        formSelect.innerHTML =
            '<option value="">— выберите форму —</option>' +
            list.map(f => `<option value="${f.id}">${esc(f.form_name)}</option>`).join('');
    }

    function closeDropdown(row) {
        const dd = row.querySelector('.drug-dropdown');
        dd.style.display = 'none';
        dd.innerHTML = '';
    }

    function openDropdown(row, items) {
        const dd = row.querySelector('.drug-dropdown');

        if (!items.length) {
            dd.innerHTML = '<div class="drug-option" data-id="" style="padding:6px 8px; cursor:default;">Ничего не найдено</div>';
        } else {
            dd.innerHTML = items
                .map(d => `<div class="drug-option" data-id="${d.id}" style="padding:6px 8px; cursor:pointer;">${esc(d.name)}</div>`)
                .join('');
        }

        dd.style.display = 'block';
    }

    // показываем все подходящие лекарства
    function filterDrugs(query) {
        const q = query.trim().toLowerCase();
        if (!q) return drugs;
        const res = [];
        for (const d of drugs) {
            if (String(d.name).toLowerCase().includes(q)) {
                res.push(d);
            }
        }
        return res;
    }

    function setDrug(row, id, name) {
        row.querySelector('.drug-input').value = name;
        row.querySelector('.drug-id-input').value = id;
        updateFormsForRow(row, id);
        closeDropdown(row);
    }

    function clearDrug(row) {
        row.querySelector('.drug-input').value = '';
        row.querySelector('.drug-id-input').value = '';
        row.querySelector('.form-select').innerHTML =
            '<option value="">— выберите лекарство —</option>';
        closeDropdown(row);
    }

    function bindRowEvents(row) {
        const input     = row.querySelector('.drug-input');
        const hiddenId  = row.querySelector('.drug-id-input');
        const dd        = row.querySelector('.drug-dropdown');
        const removeBtn = row.querySelector('.removeRowBtn');

        input.addEventListener('input', () => {
            const items = filterDrugs(input.value);
            openDropdown(row, items);
            hiddenId.value = '';
            row.querySelector('.form-select').innerHTML =
                '<option value="">— выберите лекарство —</option>';
        });

        input.addEventListener('focus', () => {
            const items = filterDrugs(input.value);
            openDropdown(row, items);
        });

        input.addEventListener('blur', () => {
            setTimeout(() => {
                if (!hiddenId.value) {
                    clearDrug(row);
                } else {
                    closeDropdown(row);
                }
            }, 150);
        });

        dd.addEventListener('mousedown', (e) => {
            const opt = e.target.closest('.drug-option');
            if (!opt) return;
            e.preventDefault();
            const id = opt.getAttribute('data-id');
            const name = opt.textContent;
            if (id) {
                setDrug(row, id, name);
            } else {
                clearDrug(row);
            }
        });

        document.addEventListener('click', (e) => {
            if (!row.contains(e.target)) {
                closeDropdown(row);
            }
        });

        removeBtn.addEventListener('click', () => {
            const rows = itemsTable.querySelectorAll('.itemRow');
            if (rows.length > 1) {
                row.remove();
            } else {
                clearDrug(row);
                row.querySelector('input[name="quantity[]"]').value = '';
            }
        });
    }

    // первая строка
    bindRowEvents(itemsTable.querySelector('.itemRow'));

    addRowBtn.addEventListener('click', () => {
        const first = itemsTable.querySelector('.itemRow');
        const clone = first.cloneNode(true);

        clone.querySelector('.drug-input').value = '';
        clone.querySelector('.drug-id-input').value = '';
        const dd = clone.querySelector('.drug-dropdown');
        dd.style.display = 'none';
        dd.innerHTML = '';
        clone.querySelector('.form-select').innerHTML =
            '<option value="">— выберите лекарство —</option>';
        clone.querySelector('input[name="quantity[]"]').value = '';

        bindRowEvents(clone);
        itemsTable.appendChild(clone);
    });
</script>

<?php include __DIR__ . '/footer.php'; ?>
