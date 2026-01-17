import { load } from '@2gis/mapgl';

let map;
let mapgl;
let popup;
const markersById = new Map();

export async function init2GisMap({ apiKey, center, zoom }) {
    mapgl = await load();

    map = new mapgl.Map('map', {
        key: apiKey,
        center: center ?? [37.618423, 55.751244], // [lon, lat]
        zoom: zoom ?? 10,
    });

    popup = new mapgl.Popup(map, {
        closeButton: false,
        offset: [0, -18],
    });

    return map;
}

// Кастомный красивый DOM-маркер (MapGL Marker поддерживает element)
function createPharmacyMarkerElement() {
    const el = document.createElement('div');
    el.style.width = '34px';
    el.style.height = '34px';
    el.style.borderRadius = '18px';
    el.style.border = '2px solid rgba(255,255,255,.9)';
    el.style.background =
        'radial-gradient(circle at 30% 30%, #ffffff, #e9f5ff 35%, #7dd3fc 70%, #0284c7)';
    el.style.boxShadow = '0 10px 22px rgba(2,132,199,.35)';
    el.style.position = 'relative';

    const cross = document.createElement('div');
    cross.textContent = '✚';
    cross.style.position = 'absolute';
    cross.style.inset = '0';
    cross.style.display = 'grid';
    cross.style.placeItems = 'center';
    cross.style.font = '800 16px/1 system-ui, -apple-system, Segoe UI, Roboto, Arial';
    cross.style.color = '#0b1220';
    cross.style.textShadow = '0 1px 0 rgba(255,255,255,.7)';
    el.appendChild(cross);

    const tail = document.createElement('div');
    tail.style.position = 'absolute';
    tail.style.left = '50%';
    tail.style.top = '100%';
    tail.style.width = '12px';
    tail.style.height = '12px';
    tail.style.transform = 'translate(-50%, -30%) rotate(45deg)';
    tail.style.background = '#0284c7';
    tail.style.borderRadius = '2px';
    tail.style.boxShadow = '0 12px 20px rgba(2,132,199,.25)';
    el.appendChild(tail);

    return el;
}

export function setPharmacyMarkers(pharmacies, onSelect) {
    // очистка старых
    for (const m of markersById.values()) m.destroy();
    markersById.clear();
    popup.close();

    pharmacies.forEach(p => {
        if (p.lon == null || p.lat == null) return;

        const marker = new mapgl.Marker(map, {
            coordinates: [p.lon, p.lat],
            element: createPharmacyMarkerElement(),
        });

        marker.on('click', () => {
            openPharmacyPopup(p);
            onSelect?.(p.id);
        });

        markersById.set(String(p.id), marker);
    });
}

export function flyToPharmacy(id, lon, lat) {
    if (!map) return;
    if (lon != null && lat != null) {
        map.setCenter([lon, lat]);
        map.setZoom(15);
    }
}

export function openPharmacyPopup(p) {
    const html = `
    <div class="popup-card">
      <div class="popup-body">
        <div class="popup-title">${escapeHtml(p.name)}</div>
        <p class="popup-hours">${escapeHtml(p.hours || 'Часы работы не указаны')}</p>
        <div class="popup-chip"><span class="popup-dot"></span> Аптека</div>
      </div>
    </div>
  `;

    popup.setCoordinates([p.lon, p.lat]);
    popup.setContent(html);
    popup.open();
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}
