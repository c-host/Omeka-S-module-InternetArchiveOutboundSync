(function () {
    'use strict';

    function selectedItemCount(form) {
        const boxes = form.querySelectorAll('.ia-publish-items-checkboxes input[type="checkbox"]:checked');
        return boxes.length;
    }

    function updateConfirmField() {
        const form = document.getElementById('ia-outbound-publish-items-form');
        if (!form) {
            return;
        }
        const threshold = parseInt(form.dataset.typeConfirmThreshold || '10', 10);
        const count = selectedItemCount(form);
        const field = document.getElementById('ia-outbound-publish-confirm-text-field');
        const help = document.getElementById('ia-outbound-publish-confirm-text-help');
        if (!field || !help) {
            return;
        }
        if (count >= threshold) {
            field.classList.remove('ia-outbound-confirm-hidden');
            help.textContent = 'Required when publishing ' + threshold + ' or more items at once. Type PUBLISH in uppercase to confirm you intend to upload ' + count + ' items to Internet Archive.';
        } else {
            field.classList.add('ia-outbound-confirm-hidden');
            help.textContent = '';
            const input = field.querySelector('#publish_confirm_text');
            if (input) {
                input.value = '';
            }
        }
    }

    function resetConfirmPanel() {
        const panel = document.getElementById('ia-outbound-publish-confirm-panel');
        if (panel) {
            panel.hidden = true;
        }
        const previews = document.getElementById('ia-outbound-publish-previews');
        if (previews) {
            previews.hidden = true;
        }
        const token = document.getElementById('publish_preview_token');
        if (token) {
            token.value = '';
        }
    }

    function syncFileOrderList(list) {
        const itemId = list.dataset.itemId;
        const input = document.querySelector('.ia-publish-file-order-input[data-item-id="' + itemId + '"]');
        const method = list.closest('.ia-publish-file-order-block')?.querySelector('.ia-publish-file-order-method');
        const items = list.querySelectorAll('.ia-publish-file-order-item');
        const mediaIds = [];

        items.forEach(function (item, index) {
            const mediaId = item.getAttribute('data-media-id');
            const isSynthetic = item.classList.contains('ia-publish-file-order-item-synthetic');
            if (mediaId && mediaId !== '0') {
                mediaIds.push(mediaId);
            }
            const indexEl = item.querySelector('.ia-publish-file-order-index');
            if (indexEl) {
                indexEl.textContent = String(index + 1);
            }
            let primary = item.querySelector('.ia-publish-file-order-primary');
            if (!isSynthetic && mediaIds.length === 1) {
                if (!primary) {
                    primary = document.createElement('span');
                    primary.className = 'ia-publish-file-order-primary';
                    primary.textContent = 'Primary';
                    item.appendChild(primary);
                }
            } else if (primary) {
                primary.remove();
            }
        });

        if (input) {
            input.value = mediaIds.join(',');
        }
        if (method) {
            method.textContent = 'Custom order set in preview';
        }
    }

    function initFileOrderList(list) {
        if (!list || list.dataset.singleFile === '1') {
            return;
        }

        let draggedItem = null;

        list.querySelectorAll('.ia-publish-file-order-item').forEach(function (item) {
            item.addEventListener('dragstart', function (event) {
                if (item.classList.contains('ia-publish-file-order-item-synthetic')) {
                    event.preventDefault();
                    return;
                }
                draggedItem = item;
                item.classList.add('ia-publish-file-order-dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', item.getAttribute('data-media-id') || '');
                }
            });

            item.addEventListener('dragend', function () {
                item.classList.remove('ia-publish-file-order-dragging');
                list.querySelectorAll('.ia-publish-file-order-drop-target').forEach(function (el) {
                    el.classList.remove('ia-publish-file-order-drop-target');
                });
                draggedItem = null;
            });

            item.addEventListener('dragover', function (event) {
                event.preventDefault();
                if (!draggedItem || draggedItem === item) {
                    return;
                }
                item.classList.add('ia-publish-file-order-drop-target');
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'move';
                }
            });

            item.addEventListener('dragleave', function () {
                item.classList.remove('ia-publish-file-order-drop-target');
            });

            item.addEventListener('drop', function (event) {
                event.preventDefault();
                item.classList.remove('ia-publish-file-order-drop-target');
                if (!draggedItem || draggedItem === item) {
                    return;
                }
                if (item.classList.contains('ia-publish-file-order-item-synthetic')
                    || draggedItem.classList.contains('ia-publish-file-order-item-synthetic')) {
                    return;
                }

                const rect = item.getBoundingClientRect();
                const insertBefore = event.clientY < rect.top + rect.height / 2;
                if (insertBefore) {
                    list.insertBefore(draggedItem, item);
                } else {
                    list.insertBefore(draggedItem, item.nextSibling);
                }
                syncFileOrderList(list);
            });
        });
    }

    function initFileOrderLists() {
        document.querySelectorAll('.ia-publish-file-order-list').forEach(initFileOrderList);
    }

    function init() {
        const form = document.getElementById('ia-outbound-publish-items-form');
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
        initFileOrderLists();

        if (form.dataset.previewReady === '1') {
            const panel = document.getElementById('ia-outbound-publish-confirm-panel');
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
