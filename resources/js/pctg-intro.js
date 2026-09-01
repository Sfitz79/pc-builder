const intro = document.querySelector('[data-pctg-intro]');

const markIntroDone = () => {
    document.body.classList.add('is-intro-done');
};

if (!intro) {
    markIntroDone();
} else {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduceMotion) {
        intro.remove();
        markIntroDone();
    } else {
        const statusItems = Array.from(intro.querySelectorAll('[data-pctg-status]'));
        const durationMs = parseInt(intro.dataset.pctgDuration || '3600', 10);

        const revealStatus = (index) => {
            if (index >= statusItems.length) {
                return;
            }

            const el = statusItems[index];
            el.hidden = false;
            el.classList.add('pctg-status');
            window.setTimeout(() => revealStatus(index + 1), 600);
        };

        const finish = () => {
            intro.classList.add('pctg-intro-exit');
            window.setTimeout(() => {
                intro.remove();
                markIntroDone();
            }, 750);
        };

        window.setTimeout(() => revealStatus(0), 900);
        window.setTimeout(finish, durationMs);
    }
}
