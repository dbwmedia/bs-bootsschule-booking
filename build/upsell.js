/**
 * BS Bootsschule Booking - anonymous upsell tracking (views and clicks)
 */
(function () {
    var box = document.querySelector('.bs-upsell[data-source]');
    var config = window.bsUpsell;
    if (!box || !config) return;

    function track(type) {
        var data = new FormData();
        data.append('action', 'bs_upsell_track');
        data.append('type', type);
        data.append('product_id', box.dataset.source);
        if (navigator.sendBeacon) {
            navigator.sendBeacon(config.ajaxUrl, data);
        } else {
            fetch(config.ajaxUrl, { method: 'POST', body: data, keepalive: true, credentials: 'same-origin' });
        }
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) {
                track('view');
                observer.disconnect();
            }
        }, { threshold: 0.5 });
        observer.observe(box);
    } else {
        track('view');
    }

    var cta = box.querySelector('.bs-upsell__cta');
    if (cta) cta.addEventListener('click', function () { track('click'); });
})();
