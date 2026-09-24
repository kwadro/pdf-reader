(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const copy = document.querySelector('.hero-copy');
    const visual = document.querySelector('.hero-visual');

    const reveal = () => {
        copy?.classList.add('is-in');
        visual?.classList.add('is-in');
    };

    if (reduce) {
        reveal();
        return;
    }

    requestAnimationFrame(() => {
        requestAnimationFrame(reveal);
    });
})();
