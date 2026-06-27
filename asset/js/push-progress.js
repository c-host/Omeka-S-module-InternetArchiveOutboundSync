(function () {
    'use strict';

    function formatSeconds(totalSeconds) {
        totalSeconds = Math.max(0, parseInt(totalSeconds, 10) || 0);
        if (totalSeconds < 60) {
            return totalSeconds + ' seconds';
        }
        const minutes = Math.ceil(totalSeconds / 60);
        return minutes + ' minute' + (minutes === 1 ? '' : 's');
    }

    function updateTimingEstimate() {
        const el = document.getElementById('ia-outbound-timing-estimate');
        const form = document.getElementById('ia-outbound-push-form');
        if (!el || !form) {
            return;
        }

        const chunkSize = parseInt(el.dataset.chunkSize || '5', 10);
        const perItemMin = parseInt(el.dataset.perItemMinSeconds || '5', 10);
        const perItemTypical = parseInt(el.dataset.perItemTypicalSeconds || '15', 10);
        const perItemMax = parseInt(el.dataset.perItemMaxSeconds || '30', 10);
        const select = form.querySelector('select.ia-item-picker-native');
        const selectedCount = select && select.iaPickerState
            ? select.iaPickerState.options.filter(function (opt) {
                return opt.selected;
            }).length
            : 0;
        const confirmPanel = document.getElementById('ia-outbound-push-confirm-panel');
        const previewReady = form.dataset.previewReady === '1';
        const showEstimate = previewReady && selectedCount > 0 && confirmPanel && !confirmPanel.hidden;

        if (!showEstimate) {
            el.hidden = true;
            el.textContent = '';
            return;
        }

        const batchCount = Math.ceil(selectedCount / Math.max(1, chunkSize));
        const minSeconds = selectedCount * perItemMin;
        const typicalSeconds = selectedCount * perItemTypical;
        const maxSeconds = selectedCount * perItemMax;

        el.hidden = false;
        el.textContent = 'Selected ' + selectedCount + ' item(s) across ' + batchCount
            + ' background batch(es). Allow about ' + formatSeconds(minSeconds) + '–' + formatSeconds(maxSeconds)
            + ' total (often ~' + formatSeconds(typicalSeconds)
            + '). Each item is verified on Internet Archive after the metadata patch is sent.';
    }

    function renderActivePushes(container, pushes) {
        if (!pushes.length) {
            container.innerHTML = '';
            container.hidden = true;
            return;
        }

        container.hidden = false;
        const blocks = pushes.map(function (push) {
            const total = push.total || 0;
            const completed = push.completed || 0;
            const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
            const estimate = push.estimate_remaining || {};
            const remainingText = (push.remaining || 0) > 0
                ? 'About ' + (estimate.min_seconds || 0) + '–' + (estimate.max_seconds || 0) + ' seconds may remain.'
                : 'Run complete.';

            return ''
                + '<div class="ia-outbound-push-progress notice" data-run-id="' + push.run_id + '">'
                + '<h3>Metadata push in progress</h3>'
                + '<p>Run #' + push.run_id + ': ' + completed + ' of ' + total
                + ' items complete (' + (push.elapsed_label || '') + ' elapsed).</p>'
                + '<div class="ia-outbound-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="'
                + total + '" aria-valuenow="' + completed + '">'
                + '<div class="ia-outbound-progress-bar-fill" style="width:' + percent + '%;"></div>'
                + '</div>'
                + '<p class="ia-outbound-progress-detail">' + remainingText + '</p>'
                + '</div>';
        });

        container.innerHTML = blocks.join('')
            + '<p class="ia-outbound-progress-refresh-note">This banner updates automatically while a push is running.</p>';
    }

    function pollActivePushes() {
        const container = document.getElementById('ia-outbound-active-pushes');
        if (!container) {
            return;
        }
        const url = container.dataset.refreshUrl;
        if (!url) {
            return;
        }

        fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('status');
                }
                return response.json();
            })
            .then(function (data) {
                renderActivePushes(container, data.active || []);
                if ((data.active || []).length) {
                    window.setTimeout(pollActivePushes, 10000);
                } else if (window.location.pathname.indexOf('/history') !== -1) {
                    window.location.reload();
                }
            })
            .catch(function () {
                window.setTimeout(pollActivePushes, 30000);
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateTimingEstimate();
        document.getElementById('ia-outbound-push-form')?.addEventListener('change', updateTimingEstimate);
        document.getElementById('ia-outbound-active-pushes')?.addEventListener('ia-selection-changed', updateTimingEstimate);

        const container = document.getElementById('ia-outbound-active-pushes');
        if (container && container.dataset.refreshUrl) {
            window.setTimeout(pollActivePushes, 10000);
        }
    });

    window.iaOutboundUpdateTimingEstimate = updateTimingEstimate;
})();
