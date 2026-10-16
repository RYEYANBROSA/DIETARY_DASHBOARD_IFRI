/**
 * The Dashboard overview map, as an Alpine component:
 *   <div x-data="overviewMap(points, statuses, swsUrl)"> ... <div x-ref="map" wire:ignore></div> </div>
 * points are rows [id, lat, lng, memberNo, name, area, status]; statuses maps a
 * status to [label, colour]. Leaflet is loaded on first use.
 */
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

window.overviewMap = (points, statuses, swsUrl) => ({
    map: null,
    loading: true,

    async init() {
        const [{ default: L }] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]);

        this.map = L.map(this.$refs.map, { preferCanvas: true, scrollWheelZoom: false });
        const street = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics',
        });
        street.addTo(this.map);
        L.control.layers({ Street: street, Satellite: satellite }, null, { position: 'topright' }).addTo(this.map);

        const bounds = [];
        for (const [id, lat, lng, memberNo, name, area, status] of points) {
            const [label, colour] = statuses[status] ?? ['', '#A8B3AC'];
            L.circleMarker([lat, lng], { radius: 6, color: '#FFFFFF', weight: 1.5, fillColor: colour, fillOpacity: 0.95 })
                .addTo(this.map)
                .bindPopup(`<div class="sws-pop">
                    <strong>${escape(memberNo)}</strong>
                    <span class="sws-pop-name">${escape(name)}</span>
                    <span>${escape(area)}</span>
                    <span style="color:${colour};font-weight:700">${escape(label)}</span>
                    ${swsUrl ? `<span class="sws-pop-actions"><a href="${swsUrl}?search=${encodeURIComponent(memberNo)}">Open in SWS</a></span>` : ''}
                </div>`, { maxWidth: 260 });
            bounds.push([lat, lng]);
        }

        if (bounds.length) {
            this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
        } else {
            this.map.setView([12.8797, 121.774], 6); // the Philippines
        }

        this.loading = false;
        setTimeout(() => this.map && this.map.invalidateSize(), 50);
    },

    destroy() {
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    },
});
