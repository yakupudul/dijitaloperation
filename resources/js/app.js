const WEBSITE_COLLECTION_PATH = '/integrations/website';
const WEBSITE_COLLECTION_POLL_MS = 5000;

function refreshWebsiteCollectionView() {
    if (window.location.pathname !== WEBSITE_COLLECTION_PATH) {
        return;
    }

    const livewire = window.Livewire;
    if (!livewire || typeof livewire.all !== 'function') {
        return;
    }

    livewire.all().forEach((component) => {
        const wire = component?.$wire;
        if (wire && typeof wire.$refresh === 'function') {
            wire.$refresh();
        }
    });
}

document.addEventListener('livewire:init', () => {
    window.setInterval(refreshWebsiteCollectionView, WEBSITE_COLLECTION_POLL_MS);
});
