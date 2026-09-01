const revealEls = document.querySelectorAll('.pctg-reveal');

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

if (revealEls.length === 0 || reduceMotion) {
    revealEls.forEach((el) => el.classList.add('is-visible'));
} else {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        },
        {
            threshold: 0.12,
            rootMargin: '0px 0px -48px 0px',
        }
    );

    revealEls.forEach((el) => observer.observe(el));
}
