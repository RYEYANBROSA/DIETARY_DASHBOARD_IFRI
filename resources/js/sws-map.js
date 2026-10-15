/**
 * The SWS household map (SWS Upload Data > View map), as an Alpine component:
 *   <div x-data="swsMap(points, focusId)"> ... <div x-ref="map" wire:ignore></div> </div>
 * points are rows [id, lat, lng, memberNo, name, address, area, contact].
 * Leaflet is loaded on first use, so other pages don't download it.
 */
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

window.swsMap = (points, focusId) => ({
    map: null,
    loading: true,

    async init() {
        const [{ default: L }] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]);

        // Circle markers on a canvas stay fast with thousands of households and need no marker images
        this.map = L.map(this.$refs.map, { preferCanvas: true, zoomControl: true });

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
        L.control.scale({ imperial: false }).addTo(this.map);

        // Households at exactly the same spot share one marker, so none hides under another
        const spots = new Map();
        for (const point of points) {
            const key = point[1] + ',' + point[2];
            if (!spots.has(key)) spots.set(key, []);
            spots.get(key).push(point);
        }

        const bounds = [];
        let focused = null;

        for (const households of spots.values()) {
            const [, lat, lng] = households[0];
            const isFocus = households.some((h) => h[0] === focusId);
            const shared = households.length > 1;
            const marker = L.circleMarker([lat, lng], {
                radius: isFocus ? 10 : shared ? 8 : 6,
                color: shared ? '#0F3D2E' : '#FFFFFF',
                weight: shared ? 2.5 : 1.5,
                fillColor: isFocus ? '#B45309' : '#15803D',
                fillOpacity: 0.9,
            }).addTo(this.map);

            const entries = households.map(([id, , , memberNo, name, address, area, contact]) => `
                <div class="sws-pop-entry">
                    <strong>${escape(memberNo)}</strong>
                    <span class="sws-pop-name">${escape(name)}</span>
                    ${address ? `<span>${escape(address)}</span>` : ''}
                    <span>${escape(area)}</span>
                    ${contact ? `<span>${escape(contact)}</span>` : ''}
                    <span class="sws-pop-actions">
                        <button type="button" data-sws-edit="${id}">Edit record</button>
                        <button type="button" data-sws-history="${id}">History</button>
                    </span>
                </div>`).join('');

            marker.bindPopup(
                `<div class="sws-pop">
                    ${shared ? `<span class="sws-pop-shared">${households.length} households at this location</span>` : ''}
                    ${entries}
                    <span class="sws-pop-coords">${lat}, ${lng} · <a href="https://www.google.com/maps?q=${lat},${lng}" target="_blank" rel="noopener">Google Maps</a></span>
                </div>`,
                { maxWidth: 300, maxHeight: 320 },
            );
            if (shared) marker.bindTooltip(`${households.length} households`, { direction: 'top', offset: [0, -8] });
            bounds.push([lat, lng]);
            if (isFocus) focused = marker;
        }

        if (focused) {
            this.map.setView(focused.getLatLng(), 18);
            focused.openPopup();
        } else if (bounds.length) {
            this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 });
        } else {
            this.map.setView([12.8797, 121.774], 6); // the Philippines
        }

        // Buttons inside popups talk to the Livewire component
        this.$refs.map.addEventListener('click', (event) => {
            const edit = event.target.closest('[data-sws-edit]');
            const history = event.target.closest('[data-sws-history]');
            if (edit) this.$wire.edit(Number(edit.dataset.swsEdit));
            if (history) this.$wire.showHistory(Number(history.dataset.swsHistory));
        });

        this.loading = false;
        // The container may have been laid out before the map existed
        setTimeout(() => this.map && this.map.invalidateSize(), 50);
    },

    destroy() {
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    },
});
