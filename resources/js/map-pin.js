/**
 * Shared by the Dashboard and SWS maps: satellite imagery (with place names on
 * top) and balloon pins. Pins are SVG div icons with a white outline and a
 * shadow so they stand out on the imagery.
 */

/** Satellite imagery plus a transparent layer of road and place names. */
export function satelliteLayers(L, map) {
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics',
    }).addTo(map);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
    }).addTo(map);
}

/**
 * A balloon pin in the given colour. size is the pin height in px; count (if
 * above 1) is shown as a badge, for households sharing one location.
 */
export function pinIcon(L, colour, { size = 30, count = 0 } = {}) {
    const width = Math.round(size * 0.75);
    const badge = count > 1
        ? `<span class="map-pin-count">${count > 99 ? '99+' : count}</span>`
        : '';

    return L.divIcon({
        className: 'map-pin',
        html: `<svg width="${width}" height="${size}" viewBox="0 0 24 32" aria-hidden="true">
                <path d="M12 1C5.9 1 1 5.9 1 12c0 8.2 9.6 17.9 10.3 18.6a1 1 0 0 0 1.4 0C13.4 29.9 23 20.2 23 12 23 5.9 18.1 1 12 1z"
                    fill="${colour}" stroke="#FFFFFF" stroke-width="2"/>
                <circle cx="12" cy="12" r="4.2" fill="#FFFFFF"/>
            </svg>${badge}`,
        iconSize: [width, size],
        // The tip of the balloon sits on the location
        iconAnchor: [width / 2, size],
        popupAnchor: [0, -size + 4],
        tooltipAnchor: [0, -size],
    });
}
