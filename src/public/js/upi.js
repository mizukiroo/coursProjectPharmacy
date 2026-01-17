function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

export function renderTable(pharmacies, onRowClick) {
    const tbody = document.querySelector('#tbl tbody');
    tbody.innerHTML = '';

    pharmacies.forEach(p => {
        const tr = document.createElement('tr');
        tr.dataset.id = String(p.id);

        tr.innerHTML = `
      <td>
        <div><b>${escapeHtml(p.name)}</b></div>
        <div class="small">${escapeHtml(p.hours || 'Часы работы не указаны')}</div>
      </td>
    `;

        tr.addEventListener('click', () => onRowClick(p.id));
        tbody.appendChild(tr);
    });
}

export function highlightRow(id) {
    document.querySelectorAll('#tbl tbody tr').forEach(tr => {
        tr.classList.toggle('row-active', tr.dataset.id === String(id));
    });
}

export function filterPharmacies(pharmacies, q) {
    const query = (q || '').trim().toLowerCase();
    if (!query) return pharmacies;
    return pharmacies.filter(p =>
        ((p.name || '') + ' ' + (p.hours || '')).toLowerCase().includes(query)
    );
}

export { escapeHtml };
