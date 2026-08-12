/**
 * Single map style/source boundary for operator maps.
 * Local rank visualization depends on geographic point data, not this tile provider.
 * Swap OpenFreeMap URLs here without redesigning GBP Visibility.
 */
export const mapConfig = {
    attribution:
        '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> · <a href="https://openfreemap.org" target="_blank" rel="noopener">OpenFreeMap</a>',
    styles: {
        light: 'https://tiles.openfreemap.org/styles/liberty',
        dark: 'https://tiles.openfreemap.org/styles/dark',
    },
};

export function resolveMapStyle() {
    const dark = document.documentElement.classList.contains('dark');

    return dark ? mapConfig.styles.dark : mapConfig.styles.light;
}
