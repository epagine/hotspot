<?php declare(strict_types=1); ?>
<script>
(function () {
    var btn = document.getElementById('app-hamburger');
    var side = document.getElementById('app-sidebar');
    if (!btn || !side) return;

    btn.addEventListener('click', function () {
        var open = side.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    side.querySelectorAll('[data-nav] a').forEach(function (a) {
        a.addEventListener('click', function () {
            side.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>
