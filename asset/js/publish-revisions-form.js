(function () {
    'use strict';

    function selectedQueueCount(form) {
        const boxes = form.querySelectorAll('.ia-publish-items-checkboxes input[type="checkbox"]:checked');
        return boxes.length;
    }

    function updateConfirmField() {
        const form = document.getElementById('ia-outbound-publish-revisions-form');
        if (!form) {
            return;
        }
        const threshold = parseInt(form.dataset.typeConfirmThreshold || '10', 10);
        const count = selectedQueueCount(form);
        const field = document.getElementById('ia-outbound-revisions-confirm-text-field');
        const help = document.getElementById('ia-outbound-revisions-confirm-text-help');
        if (!field || !help) {
            return;
        }
        if (count >= threshold) {
            field.classList.remove('ia-outbound-confirm-hidden');
            help.textContent = 'Required when pushing ' + threshold + ' or more revisions at once. Type PUSH in uppercase to confirm you intend to update ' + count + ' items on Internet Archive.';
        } else {
            field.classList.add('ia-outbound-confirm-hidden');
            help.textContent = '';
            const input = field.querySelector('#publish_revisions_confirm_text');
            if (input) {
                input.value = '';
            }
        }
    }

    function resetConfirmPanel() {
        const panel = document.getElementById('ia-outbound-revisions-confirm-panel');
        if (panel) {
            panel.hidden = true;
        }
        const previews = document.getElementById('ia-outbound-revisions-previews');
        if (previews) {
            previews.hidden = true;
        }
        const token = document.getElementById('publish_revisions_preview_token');
        if (token) {
            token.value = '';
        }
    }

    function init() {
        const form = document.getElementById('ia-outbound-publish-revisions-form');
        if (!form) {
            return;
        }

        form.querySelectorAll('.ia-publish-items-checkboxes input[type="checkbox"]').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                resetConfirmPanel();
                updateConfirmField();
            });
        });

        updateConfirmField();

        if (form.dataset.previewReady === '1' && form.dataset.metadataPushEnabled === '1') {
            const panel = document.getElementById('ia-outbound-revisions-confirm-panel');
            if (panel) {
                panel.hidden = false;
            }
            updateConfirmField();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
