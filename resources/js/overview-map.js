/**
 * The Dashboard overview map, as an Alpine component:
 *   <div x-data="overviewMap(points, statuses, swsUrl)"> ... <div x-ref="map" wire:ignore></div> </div>
 * points are rows [id, lat, lng, memberNo, name, area, status]; statuses maps a
 * status to [label, colour]: each household is a balloon pin in its status
 * colour, on satellite imagery. Leaflet is loaded on first use.
 */
import { pinIcon, satelliteLayers } from './map-pin';

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

window.overviewMap = (points, statuses, swsUrl) => ({
    map: null,
    loading: true,

    async init() {
        const [{ default: L }] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]);

        this.map = L.map(this.$refs.map, { scrollWheelZoom: false });
        // Always satellite
        satelliteLayers(L, this.map);

        const bounds = [];
        for (const [id, lat, lng, memberNo, name, area, status] of points) {
            const [label, colour] = statuses[status] ?? ['', '#A8B3AC'];
            L.marker([lat, lng], { icon: pinIcon(L, colour), title: `${memberNo} · ${label}` })
                .addTo(this.map)
                .bindPopup(`<div class="sws-pop">
                    <strong>${escape(memberNo)}</strong>
                    <span class="sws-pop-name">${escape(name)}</span>
                    <span>${escape(area)}</span>
                    <span style="font-weight:700"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px;background:${colour}"></span>${escape(label)}</span>
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
