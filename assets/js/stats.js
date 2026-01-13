(function () {
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof jankx_stats_params === 'undefined') return;

        var payload = new URLSearchParams();
        payload.append('action', 'jankx_track_view');
        payload.append('_wpnonce', jankx_stats_params.nonce);
        payload.append('post_id', jankx_stats_params.post_id);

        if (navigator.sendBeacon) {
            navigator.sendBeacon(jankx_stats_params.ajax_url, payload);
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', jankx_stats_params.ajax_url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.send(payload.toString());
        }
    });
})();
