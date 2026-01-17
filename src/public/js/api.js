export async function apiGetPharmacies() {
    const res = await fetch('/api/pharmacies', { headers: { 'Accept': 'application/json' } });
    if (!res.ok) throw new Error(`API error: ${res.status}`);
    return await res.json();
}
