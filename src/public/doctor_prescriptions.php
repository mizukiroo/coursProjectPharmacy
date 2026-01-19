<?php
require_once __DIR__ . '/auth.php';
$user = require_role('doctor');
global $pdo;

$doctorId = (int)($user['role_id'] ?? 0);
if ($doctorId <= 0) {
    http_response_code(500);
    exit('Doctor not found');
}

$error = null;
$success = null;

/* =========================
   СОХРАНЕНИЕ РЕЦЕПТА
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $comment    = trim($_POST['comment'] ?? '');

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

    if ($customerId <= 0 || empty($items)) {
        $error = 'Выберите пациента и добавьте хотя бы одну строку с лекарством и количеством.';
    } else {
        try {
            $pdo->beginTransaction();

            // шапка рецепта
            $st = $pdo->prepare("
                INSERT INTO prescriptions (customer_id, doctor_id, prescription_date, comment)
                VALUES (?, ?, CURDATE(), ?)
            ");
            $st->execute([$customerId, $doctorId, ($comment !== '' ? $comment : null)]);
            $prescriptionId = (int)$pdo->lastInsertId();

            // проверка разрешённой формы для лекарства (как у аптекаря)
            $checkDf = $pdo->prepare("
                SELECT 1
                FROM drug_forms
                WHERE drug_id = ? AND form_id = ?
            ");

            // вставка строк
            $insItem = $pdo->prepare("
                INSERT INTO prescription_items (prescription_id, drug_id, form_id, quantity)
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
                $insItem->execute([$prescriptionId, $it['drug_id'], $it['form_id'], $it['qty']]);
            }

            $pdo->commit();
            $success = 'Рецепт сохранён.';

            // очистим поля формы (чтобы не оставались старые значения)
            $_POST = [];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Ошибка при сохранении рецепта: ' . $e->getMessage();
        }
    }
}

/* =========================
   СПРАВОЧНИКИ
========================= */

// пациенты
$patients = $pdo->query("
    SELECT c.id, u.full_name, u.login
    FROM customers c
    JOIN users u ON u.id = c.id_user
    ORDER BY COALESCE(u.full_name, u.login)
")->fetchAll(PDO::FETCH_ASSOC);

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

/* =========================
   СПИСОК РЕЦЕПТОВ ВНИЗУ
========================= */
$prescriptions = [];
$stmt = $pdo->prepare("
    SELECT p.id, p.prescription_date, p.comment, u.full_name AS patient_name
    FROM prescriptions p
    JOIN customers c ON c.id = p.customer_id
    JOIN users u ON u.id = c.id_user
    WHERE p.doctor_id = ?
    ORDER BY p.prescription_date DESC, p.id DESC
    LIMIT 30
");
$stmt->execute([$doctorId]);
$prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// запрос для позиций
$stmtItems = $pdo->prepare("
    SELECT d.name AS drug_name, f.form_name, pi.quantity
    FROM prescription_items pi
    JOIN drugs d ON d.id = pi.drug_id
    JOIN forms f ON f.id = pi.form_id
    WHERE pi.prescription_id = ?
    ORDER BY d.name, f.form_name
");

include __DIR__ . '/header.php';
?>

<div class="container">
    <h1>Рецепты пациентов</h1>

    <?php if ($error): ?>
        <div class="card" style="border-color:#fca5a5;">
            <div class="cardHeader">Ошибка</div>
            <div class="muted"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="card" style="border-color:#86efac; margin-top: 12px;">
            <div class="cardHeader">Готово</div>
            <div class="muted"><?= htmlspecialchars($success) ?></div>
        </div>
    <?php endif; ?>

    <!-- форма создания НОВОГО рецепта с несколькими препаратами -->
    <div class="card" style="margin-top:15px;">
        <div class="cardHeader">Новый рецепт</div>

        <form method="post" id="prescriptionForm">
            <div class="pharmToolbar" style="margin-bottom: 16px;">
                <div class="pharmField">
                    <label>Пациент</label>
                    <select name="customer_id">
                        <option value="">— выберите пациента —</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= (int)$p['id'] ?>">
                                <?= htmlspecialchars($p['full_name'] ?: $p['login']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pharmField" style="margin-bottom: 12px;">
                <label>Комментарий</label>
                <input type="text" name="comment" placeholder="Например: курс 10 дней, по 1 таб. 2 раза в день">
            </div>

            <div class="muted" style="margin-bottom:8px;">
                В одном рецепте можно указать сразу несколько лекарств.
            </div>

            <table class="table" id="itemsTable" style="width:100%; border-collapse:separate; border-spacing:0 6px;">
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
                <tr class="itemRow">
                    <td>
                        <!-- СТИЛЬ 1:1 КАК У pharmacist_receipts.php -->
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
                <button class="btn btn-primary" style="height:42px;">Сохранить рецепт</button>
            </div>
        </form>
    </div>

    <!-- список рецептов -->
    <?php if (empty($prescriptions)): ?>
        <div class="card" style="margin-top:15px;">
            <div class="cardHeader">Рецептов пока нет</div>
            <div class="muted">Вы ещё не выписали ни одного рецепта.</div>
        </div>
    <?php else: ?>
        <?php foreach ($prescriptions as $r): ?>
            <div class="card" style="margin-top:15px;">
                <div class="cardHeader">
                    Рецепт №<?= (int)$r['id'] ?> —
                    <?= htmlspecialchars(date('d.m.Y', strtotime($r['prescription_date']))) ?>
                </div>
                <div class="muted" style="margin-bottom:8px;">
                    Пациент: <?= htmlspecialchars($r['patient_name']) ?>
                </div>

                <?php
                $stmtItems->execute([(int)$r['id']]);
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

                <?php if (!empty($r['comment'])): ?>
                    <div class="muted" style="margin-top:8px;">
                        Комментарий: <?= htmlspecialchars($r['comment']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
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

    function filterDrugs(query) {
        const q = query.trim().toLowerCase();
        if (!q) return drugs;
        const res = [];
        for (const d of drugs) {
            if (String(d.name).toLowerCase().includes(q)) res.push(d);
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
            if (id) setDrug(row, id, name);
            else clearDrug(row);
        });

        document.addEventListener('click', (e) => {
            if (!row.contains(e.target)) closeDropdown(row);
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
