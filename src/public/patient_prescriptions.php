<?php
// patient_prescriptions.php
// Пациент: рецепты + бронирование по каждой позиции (разные аптеки), живой поиск аптек
// Наличие: receipts/receipt_items - orders/order_items (кроме cancelled)
// Лимит по рецепту: (назначено - уже заказано по этой строке)

if (isset($_GET['ajax']) && $_GET['ajax'] === 'stock') {
    require_once __DIR__ . '/auth.php';
    $user = require_role('patient');
    global $pdo;

    header('Content-Type: application/json; charset=utf-8');

    $clinicId = (int)($_GET['clinic_id'] ?? 0);
    $drugId   = (int)($_GET['drug_id'] ?? 0);
    $formId   = (int)($_GET['form_id'] ?? 0);

    if ($clinicId <= 0 || $drugId <= 0 || $formId <= 0) {
        echo json_encode(['ok' => true, 'available' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // приход
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(ri.quantity), 0)
        FROM receipts r
        JOIN receipt_items ri ON ri.receipt_id = r.id
        WHERE r.clinic_id = ?
          AND ri.drug_id = ?
          AND ri.form_id = ?
    ");
    $st->execute([$clinicId, $drugId, $formId]);
    $incoming = (int)$st->fetchColumn();

    // расход (кроме cancelled)
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0)
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.clinic_id = ?
          AND o.status <> 'cancelled'
          AND oi.drug_id = ?
          AND oi.form_id = ?
    ");
    $st->execute([$clinicId, $drugId, $formId]);
    $outgoing = (int)$st->fetchColumn();

    $available = $incoming - $outgoing;
    if ($available < 0) $available = 0;

    echo json_encode(['ok' => true, 'available' => $available], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/header.php';

if (!isset($user) || ($user['role'] ?? null) !== 'patient') {
    header('Location: login.php');
    exit;
}

$customerId = (int)($user['role_id'] ?? 0);
if ($customerId <= 0) {
    echo '<div class="container"><div class="card"><div class="cardHeader">Ошибка</div><div class="muted">Не удалось определить профиль пациента.</div></div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// аптеки для live-поиска
$clinics = $pdo->query("
    SELECT id, short_name, full_name
    FROM clinics
    ORDER BY COALESCE(short_name, full_name), full_name
")->fetchAll(PDO::FETCH_ASSOC);

$clinicList = [];
foreach ($clinics as $c) {
    $clinicList[] = [
            'id'   => (int)$c['id'],
            'name' => (string)($c['short_name'] ?? $c['full_name']),
    ];
}

// рецепты пациента
$st = $pdo->prepare("
    SELECT
        p.id,
        p.prescription_date,
        p.comment,
        duser.full_name AS doctor_name
    FROM prescriptions p
    LEFT JOIN doctors d ON d.id = p.doctor_id
    LEFT JOIN users duser ON duser.id = d.id_user
    WHERE p.customer_id = ?
    ORDER BY p.prescription_date DESC, p.id DESC
");
$st->execute([$customerId]);
$prescriptions = $st->fetchAll(PDO::FETCH_ASSOC);

// позиции рецепта + сколько уже заказано по этой строке
$stmtItems = $pdo->prepare("
    SELECT 
        pi.id,
        pi.drug_id,
        pi.form_id,
        COALESCE(pi.quantity, 0) AS quantity,
        d.name AS drug_name,
        COALESCE(f.form_name, '') AS drug_form,
        (
          SELECT COALESCE(SUM(oi.quantity),0)
          FROM orders o
          JOIN order_items oi ON oi.order_id = o.id
          WHERE o.prescription_id = pi.prescription_id
            AND o.status <> 'cancelled'
            AND oi.prescription_item_id = pi.id
        ) AS ordered_qty
    FROM prescription_items pi
    JOIN drugs d ON d.id = pi.drug_id
    LEFT JOIN forms f ON f.id = pi.form_id
    WHERE pi.prescription_id = ?
    ORDER BY d.name
");
?>
<div class="container">
    <h1>Мои рецепты</h1>
    <div class="muted" style="margin-bottom:16px;">
        Выберите аптеку для каждого препарата и укажите количество (не больше остатка по рецепту и наличия в аптеке).
    </div>

    <?php if (empty($prescriptions)): ?>
        <div class="card">
            <div class="cardHeader">Рецептов нет</div>
            <div class="muted">Как только врач оформит рецепт — он появится здесь.</div>
        </div>
    <?php elseif (empty($clinics)): ?>
        <div class="card" style="border-color:#fca5a5;">
            <div class="cardHeader">Нет аптек</div>
            <div class="muted">Таблица <code>clinics</code> пуста — оформить заказ нельзя.</div>
        </div>
    <?php else: ?>

        <?php foreach ($prescriptions as $p): ?>
            <?php
            $pid = (int)$p['id'];
            $stmtItems->execute([$pid]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $hasAnyLeft = false;
            foreach ($items as $it) {
                $left = max(0, (int)$it['quantity'] - (int)$it['ordered_qty']);
                if ($left > 0) { $hasAnyLeft = true; break; }
            }
            ?>

            <div class="card" style="margin-top:15px;">
                <div class="cardHeader">
                    Рецепт №<?= $pid ?> —
                    <?= htmlspecialchars(date('d.m.Y', strtotime($p['prescription_date']))) ?>
                </div>

                <div class="muted" style="margin-bottom:8px;">
                    Врач: <?= htmlspecialchars($p['doctor_name'] ?? '—') ?>
                </div>

                <?php if (!empty($p['comment'])): ?>
                    <div class="muted" style="margin-bottom:10px;">
                        <?= nl2br(htmlspecialchars($p['comment'])) ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($items)): ?>
                    <div class="muted">В этом рецепте нет позиций.</div>
                <?php elseif (!$hasAnyLeft): ?>
                    <div class="muted">По этому рецепту всё уже заказано (или отменено не учитывается).</div>
                <?php else: ?>

                    <form method="post" action="order_create.php">
                        <input type="hidden" name="prescription_id" value="<?= $pid ?>">

                        <ul style="margin:0 0 0 18px;">
                            <?php foreach ($items as $it): ?>
                                <?php
                                $piId = (int)$it['id'];
                                $drugId = (int)$it['drug_id'];
                                $formId = (int)$it['form_id'];
                                $qty = (int)$it['quantity'];
                                $ordered = (int)$it['ordered_qty'];
                                $left = max(0, $qty - $ordered);
                                ?>
                                <li class="drugOrderItem"
                                    data-pi-id="<?= $piId ?>"
                                    data-drug-id="<?= $drugId ?>"
                                    data-form-id="<?= $formId ?>"
                                    data-left="<?= $left ?>"
                                    style="margin-bottom:12px;">
                                    <div>
                                        <strong><?= htmlspecialchars($it['drug_name']) ?></strong>
                                        <?php if (!empty($it['drug_form'])): ?>
                                            <span class="muted"> (<?= htmlspecialchars($it['drug_form']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="muted">
                                        Назначено: <?= $qty ?> · Уже заказано: <?= $ordered ?> · Осталось: <?= $left ?>
                                    </div>

                                    <?php if ($left <= 0): ?>
                                        <div class="muted" style="margin-top:6px;">Нечего заказывать по этой позиции.</div>
                                    <?php else: ?>
                                        <div class="pharmToolbar" style="align-items:flex-end; gap:10px; margin-top:8px; flex-wrap:wrap;">
                                            <div class="pharmField" style="min-width:260px; flex:1 1 260px;">
                                                <label>Аптека</label>
                                                <div class="clinic-select-wrapper" style="position:relative; width:100%;">
                                                    <input type="text"
                                                           class="clinic-input"
                                                           placeholder="Начните вводить аптеку..."
                                                           autocomplete="off"
                                                           style="width:100%; padding:6px 8px; border-radius:8px; border:1px solid #e6e8f0; box-sizing:border-box;">
                                                    <input type="hidden"
                                                           class="clinic-id-input"
                                                           name="items[<?= $piId ?>][clinic]">
                                                    <div class="clinic-dropdown"
                                                         style="position:absolute; left:0; right:0; top:100%; background:#fff; border:1px solid #e6e8f0; border-radius:8px; max-height:220px; overflow-y:auto; display:none; z-index:10;"></div>
                                                </div>
                                            </div>

                                            <div class="pharmField" style="min-width:140px;">
                                                <label>Количество</label>
                                                <input type="number"
                                                       class="qty-input"
                                                       name="items[<?= $piId ?>][qty]"
                                                       min="0"
                                                       value="0"
                                                       disabled
                                                       style="width:100%; padding:6px 8px; border-radius:8px; border:1px solid #e6e8f0; box-sizing:border-box;">
                                            </div>

                                            <div class="muted stockText" style="padding-bottom:6px; min-width:260px;">
                                                В аптеке: —
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <div style="margin-top:10px;">
                            <button class="btn btn-primary" style="height:42px;">
                                Забронировать выбранное
                            </button>
                        </div>
                    </form>

                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
    const clinics = <?= json_encode($clinicList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function esc(str) {
        return String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    function filterClinics(query) {
        const q = query.trim().toLowerCase();
        if (!q) return clinics;
        const res = [];
        for (const c of clinics) {
            if (String(c.name).toLowerCase().includes(q)) res.push(c);
        }
        return res;
    }

    function closeDropdown(item) {
        const dd = item.querySelector('.clinic-dropdown');
        dd.style.display = 'none';
        dd.innerHTML = '';
    }

    function openDropdown(item, list) {
        const dd = item.querySelector('.clinic-dropdown');
        if (!list.length) {
            dd.innerHTML = '<div class="clinic-option" data-id="" style="padding:6px 8px; cursor:default;">Ничего не найдено</div>';
        } else {
            dd.innerHTML = list.map(c =>
                `<div class="clinic-option" data-id="${c.id}" style="padding:6px 8px; cursor:pointer;">${esc(c.name)}</div>`
            ).join('');
        }
        dd.style.display = 'block';
    }

    async function fetchAvailable(clinicId, drugId, formId) {
        const url = `patient_prescriptions.php?ajax=stock&clinic_id=${encodeURIComponent(clinicId)}&drug_id=${encodeURIComponent(drugId)}&form_id=${encodeURIComponent(formId)}`;
        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const j = await r.json();
        return (j && j.ok) ? (parseInt(j.available, 10) || 0) : 0;
    }

    function resetControls(item) {
        const qtyInput = item.querySelector('.qty-input');
        const stockEl  = item.querySelector('.stockText');
        if (qtyInput) {
            qtyInput.value = 0;
            qtyInput.disabled = true;
            qtyInput.removeAttribute('max');
        }
        if (stockEl) stockEl.textContent = 'В аптеке: —';
    }

    async function updateStockAndLimits(item) {
        const hiddenId = item.querySelector('.clinic-id-input');
        const qtyInput = item.querySelector('.qty-input');
        const stockEl  = item.querySelector('.stockText');

        const clinicId = parseInt(hiddenId?.value || '0', 10);
        const drugId = parseInt(item.dataset.drugId || '0', 10);
        const formId = parseInt(item.dataset.formId || '0', 10);
        const leftByRx = parseInt(item.dataset.left || '0', 10);

        if (!clinicId || !drugId || !formId || !qtyInput || !stockEl) {
            resetControls(item);
            return;
        }

        const available = await fetchAvailable(clinicId, drugId, formId);
        const maxOrder = Math.max(0, Math.min(leftByRx, available));

        stockEl.textContent = `В аптеке: ${available}. Можно заказать: ${maxOrder}`;

        if (maxOrder <= 0) {
            qtyInput.value = 0;
            qtyInput.disabled = true;
            qtyInput.max = "0";
            return;
        }

        qtyInput.disabled = false;
        qtyInput.max = String(maxOrder);

        const cur = parseInt(qtyInput.value || '0', 10) || 0;
        if (cur > maxOrder) qtyInput.value = String(maxOrder);
    }

    function bindClinicSearch(item) {
        const input    = item.querySelector('.clinic-input');
        const hiddenId = item.querySelector('.clinic-id-input');
        const dd       = item.querySelector('.clinic-dropdown');
        const qtyInput = item.querySelector('.qty-input');

        if (!input || !hiddenId || !dd) return;

        function setClinic(id, name) {
            input.value = name;
            hiddenId.value = id;
            closeDropdown(item);
            updateStockAndLimits(item);
        }

        function clearClinic() {
            input.value = '';
            hiddenId.value = '';
            closeDropdown(item);
            resetControls(item);
        }

        input.addEventListener('input', () => {
            const list = filterClinics(input.value);
            openDropdown(item, list);
            hiddenId.value = '';
            resetControls(item);
        });

        input.addEventListener('focus', () => {
            const list = filterClinics(input.value);
            openDropdown(item, list);
        });

        input.addEventListener('blur', () => {
            setTimeout(() => {
                if (!hiddenId.value) clearClinic();
                else closeDropdown(item);
            }, 150);
        });

        dd.addEventListener('mousedown', (e) => {
            const opt = e.target.closest('.clinic-option');
            if (!opt) return;
            e.preventDefault();
            const id = opt.getAttribute('data-id');
            const name = opt.textContent;
            if (id) setClinic(id, name);
            else clearClinic();
        });

        document.addEventListener('click', (e) => {
            if (!item.contains(e.target)) closeDropdown(item);
        });

        if (qtyInput) {
            qtyInput.addEventListener('input', () => {
                const max = parseInt(qtyInput.max || '0', 10) || 0;
                let v = parseInt(qtyInput.value || '0', 10) || 0;
                if (v < 0) v = 0;
                if (max > 0 && v > max) v = max;
                qtyInput.value = String(v);
            });
        }
    }

    document.querySelectorAll('.drugOrderItem').forEach(bindClinicSearch);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
