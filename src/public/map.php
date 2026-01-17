<?php
require_once __DIR__ . '/config.php';
$page_class = 'page-map';

include __DIR__ . '/header.php';

// Сегодняшний день недели и текущее время
$todayDow = (int)date('N');       // 1–7 (1 = понедельник)
$nowTime  = date('H:i:s');

// Загружаем аптеки с режимом работы на сегодня
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.full_name,
        c.short_name,
        c.has_drugstore,
        a.address_line,
        a.lon,
        a.lat,
        wh.open_time,
        wh.close_time,
        CASE 
            WHEN wh.open_time IS NOT NULL 
             AND wh.close_time IS NOT NULL
             AND :now_time BETWEEN wh.open_time AND wh.close_time
            THEN 1
            ELSE 0
        END AS is_open_now
    FROM clinics c
    LEFT JOIN addresses a 
        ON a.id = c.id_address
    LEFT JOIN clinic_working_hours wh
        ON wh.id_clinic = c.id
       AND wh.day_of_week = :dow
    WHERE c.has_drugstore = 1
    ORDER BY c.full_name
");

$stmt->execute([
        'dow'      => $todayDow,
        'now_time' => $nowTime,
]);
$clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Данные для JS
$clinicsForJs = [];
foreach ($clinics as $row) {
    $lon = $row['lon'] !== null ? (float)$row['lon'] : null;
    $lat = $row['lat'] !== null ? (float)$row['lat'] : null;

    $clinicsForJs[] = [
            'id'         => (int)$row['id'],
            'short_name' => $row['short_name'] ?: $row['full_name'],
            'full_name'  => $row['full_name'],
            'address'    => $row['address_line'],
            'is_open'    => (bool)$row['is_open_now'],
            'open_time'  => $row['open_time'],
            'close_time' => $row['close_time'],
            'lon'        => $lon,
            'lat'        => $lat,
    ];
}

?>
<div class="container pageHeader">
    <h1>Карта социальных аптек</h1>
    <p class="muted">Справа карта, слева — быстрый поиск по названию поликлиники или аптеки.</p>
</div>

<div class="container">
    <div class="mapPage">
        <!-- ЛЕВО: поиск -->
        <aside class="mapPage-sidebar">
            <div class="mapPage-sidebarHeader">
                <div class="mapPage-sidebarTitle">Поиск аптеки</div>
                <p class="mapPage-sidebarText">
                    Начните вводить название поликлиники или социальной аптеки.
                </p>
            </div>

            <label class="field mapPage-searchField">
                <span class="fieldLabel">Название аптеки / поликлиники</span>
                <input type="search" id="clinicSearch" class="input"
                       placeholder="Например: «Социальная аптека №3»">
            </label>

            <div class="mapPage-results" id="clinicResults">
                <div class="mapPage-resultsPlaceholder">
                    Здесь появятся результаты быстрого поиска.
                </div>
            </div>
        </aside>

        <!-- ПРАВО: карта -->
        <section class="mapPage-map">
            <div id="map" class="mapPage-mapCanvas"></div>

            <div class="mapPage-infoPanel" id="mapInfoPanel">
                <div class="mapPage-infoEmpty">
                    Нажмите на знак аптеки на карте или выберите её в результатах поиска.
                </div>
            </div>
        </section>
    </div>
</div>


<script src="https://mapgl.2gis.com/api/js/v1"></script>
<script>
    const CLINICS = <?=
            json_encode(
                    $clinicsForJs,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            ?>;

    document.addEventListener('DOMContentLoaded', function () {
        if (!window.mapgl || !document.getElementById('map')) {
            return;
        }

        const firstWithCoords = CLINICS.find(c => typeof c.lon === 'number' && typeof c.lat === 'number');

        const map = new mapgl.Map('map', {
            key: 'b2dc9e61-944f-4d72-8f20-48dc738b64a7',
            center: firstWithCoords ? [firstWithCoords.lon, firstWithCoords.lat] : [37.618423, 55.751244],
            zoom: firstWithCoords ? 12 : 10,
        });

        const markers = new Map();

        CLINICS.forEach(c => {
            if (typeof c.lon === 'number' && typeof c.lat === 'number') {
                const marker = new mapgl.Marker(map, {
                    coordinates: [c.lon, c.lat],
                });
                markers.set(c.id, marker);

                marker.on('click', () => {
                    showClinicInfo(c);
                });
            }
        });

        const searchInput   = document.getElementById('clinicSearch');
        const resultsBox    = document.getElementById('clinicResults');
        const infoPanel     = document.getElementById('mapInfoPanel');

        if (searchInput && resultsBox) {
            searchInput.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                resultsBox.innerHTML = '';

                if (q.length === 0) {
                    resultsBox.innerHTML = '<div class="mapResultsPlaceholder">' +
                        'Начните вводить название, чтобы увидеть варианты.' +
                        '</div>';
                    return;
                }

                const matches = CLINICS.filter(c => {
                    const text = ((c.short_name || '') + ' ' + (c.full_name || '') + ' ' + (c.address || '')).toLowerCase();
                    return text.includes(q);
                });

                if (matches.length === 0) {
                    resultsBox.innerHTML = '<div class="mapResultsPlaceholder">Ничего не найдено.</div>';
                    return;
                }

                const list = document.createElement('div');
                list.className = 'mapResultsList';

                matches.forEach(c => {
                    const isOpen = !!c.is_open;
                    const item   = document.createElement('button');
                    item.type    = 'button';
                    item.className = 'mapResultItem ' + (isOpen ? 'mapResultItem--open' : 'mapResultItem--closed');
                    item.dataset.clinicId = c.id;

                    const statusText = isOpen ? 'Открыта сейчас' : 'Сейчас не работает';

                    item.innerHTML = `
                        <div class="mapResultName">${escapeHtml(c.short_name || c.full_name)}</div>
                        <div class="mapResultMeta">
                            <span class="mapClinicStatus mapClinicStatus--${isOpen ? 'open' : 'closed'}">
                                ${statusText}
                            </span>
                            ${c.open_time && c.close_time ? `
                                <span class="mapResultHours">
                                    Сегодня: ${c.open_time.slice(0,5)}–${c.close_time.slice(0,5)}
                                </span>` : ''
                    }
                            ${c.address ? `
                                <span class="mapResultAddress">${escapeHtml(c.address)}</span>` : ''
                    }
                        </div>
                    `;

                    item.addEventListener('click', () => {
                        if (typeof c.lon === 'number' && typeof c.lat === 'number') {
                            map.setCenter([c.lon, c.lat]);
                            map.setZoom(14);
                        }
                        showClinicInfo(c);
                    });

                    list.appendChild(item);
                });

                resultsBox.appendChild(list);
            });
        }

        function showClinicInfo(clinic) {
            if (!infoPanel) return;
            const isOpen     = !!clinic.is_open;
            const statusText = isOpen ? 'Открыта сейчас' : 'Сейчас не работает';
            const statusClass = isOpen ? 'mapClinicStatus--open' : 'mapClinicStatus--closed';

            const hoursLine = (clinic.open_time && clinic.close_time)
                ? `<div class="mapInfoHours">Сегодня: ${clinic.open_time.slice(0,5)}–${clinic.close_time.slice(0,5)}</div>`
                : '';

            infoPanel.innerHTML = `
                <div class="mapInfoTitle">${escapeHtml(clinic.short_name || clinic.full_name)}</div>
                <div class="mapInfoStatus ${statusClass}">${statusText}</div>
                ${hoursLine}
                ${clinic.address ? `<div class="mapInfoAddress">${escapeHtml(clinic.address)}</div>` : ''}
                <div class="mapInfoHint">
                    Режим работы рассчитывается по расписанию на сегодня.
                    В праздничные дни график может отличаться.
                </div>
            `;
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c] || c;
            });
        }
    });
</script>

<?php include __DIR__ . '/footer.php'; ?>
