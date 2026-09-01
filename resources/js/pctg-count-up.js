const counters = Array.from(document.querySelectorAll('[data-pctg-count]'));

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const animate = (el) => {
    const target = parseFloat(el.dataset.pctgCount);
    const suffix = el.dataset.pctgSuffix || '';
    const prefix = el.dataset.pctgPrefix || '';
    const decimals = parseInt(el.dataset.pctgDecimals || '0', 10);

    if (reduceMotion) {
        el.textContent = prefix + target.toFixed(decimals) + suffix;
        return;
    }

    const durationMs = 1400;
    const start = performance.now();

    const tick = (now) => {
        const progress = Math.min((now - start) / durationMs, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = prefix + (target * eased).toFixed(decimals) + suffix;

        if (progress < 1) {
            requestAnimationFrame(tick);
        }
    };

    requestAnimationFrame(tick);
};

if (counters.length > 0) {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    animate(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.4 }
    );

    counters.forEach((el) => observer.observe(el));
}
