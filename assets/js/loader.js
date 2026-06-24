const bar = document.getElementById('loader-bar');

function show() {
    if (!bar) return;
    bar.classList.remove('loader-running');
    // Force reflow so the animation restarts cleanly
    void bar.offsetWidth;
    bar.classList.add('loader-running');
}

// Liens de navigation (même origine, pas _blank, pas ancre pure)
document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href]');
    if (!link || link.target === '_blank') return;
    const href = link.getAttribute('href') ?? '';
    if (href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:')) return;
    if (href.startsWith('http') && !href.startsWith(window.location.origin)) return;
    show();
});

// Soumissions de formulaires (après confirm() éventuel)
document.addEventListener('submit', () => show());

// Bfcache : réinitialiser si l'utilisateur revient en arrière
window.addEventListener('pageshow', (e) => {
    if (e.persisted && bar) bar.classList.remove('loader-running');
});
