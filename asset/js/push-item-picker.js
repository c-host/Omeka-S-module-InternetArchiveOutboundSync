(function () {
    'use strict';

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function highlightMatch(label, query) {
        if (!query) {
            return escapeHtml(label);
        }
        const lowerLabel = label.toLowerCase();
        const lowerQuery = query.toLowerCase();
        const index = lowerLabel.indexOf(lowerQuery);
        if (index === -1) {
            return escapeHtml(label);
        }
        const before = label.slice(0, index);
        const match = label.slice(index, index + query.length);
        const after = label.slice(index + query.length);
        return escapeHtml(before) + '<mark>' + escapeHtml(match) + '</mark>' + escapeHtml(after);
    }

    function createPickerState(select) {
        return {
            select: select,
            options: Array.from(select.options).map(function (option) {
                return {
                    value: option.value,
                    label: option.text,
                    selected: option.selected,
                };
            }),
        };
    }

    function setPickerOptions(state, items) {
        state.options = items.map(function (item) {
            return {
                value: String(item.id),
                label: item.label,
                selected: false,
            };
        });
        state.select.innerHTML = '';
        state.options.forEach(function (opt) {
            const option = document.createElement('option');
            option.value = opt.value;
            option.textContent = opt.label;
            option.selected = opt.selected;
            state.select.appendChild(option);
        });
    }

    function initPicker(root) {
        const select = root.querySelector('select.ia-item-picker-native');
        if (!select) {
            return null;
        }

        const state = select.iaPickerState || createPickerState(select);
        select.iaPickerState = state;

        const selectedEl = root.querySelector('.ia-item-picker-selected');
        const searchEl = root.querySelector('.ia-item-picker-search');
        const listEl = root.querySelector('.ia-item-picker-list');
        const countEl = root.querySelector('.ia-item-picker-count');
        const selectAllBtn = root.querySelector('.ia-item-picker-select-all');
        const selectMatchingBtn = root.querySelector('.ia-item-picker-select-matching');
        const clearBtn = root.querySelector('.ia-item-picker-clear');
        const emptyEl = root.querySelector('.ia-item-picker-empty');

        function getSelectedValues() {
            return state.options.filter(function (opt) {
                return opt.selected;
            }).map(function (opt) {
                return opt.value;
            });
        }

        function syncNativeSelect() {
            Array.from(state.select.options).forEach(function (option) {
                const match = state.options.find(function (opt) {
                    return opt.value === option.value;
                });
                option.selected = !!(match && match.selected);
            });
        }

        function onSelectionChanged() {
            syncNativeSelect();
            invalidatePreview();
            updateConfirmField();
            if (typeof window.iaOutboundUpdateTimingEstimate === 'function') {
                window.iaOutboundUpdateTimingEstimate();
            }
            document.getElementById('ia-outbound-push-form')?.dispatchEvent(
                new CustomEvent('ia-selection-changed')
            );
        }

        select.iaSyncPicker = syncNativeSelect;

        function updateCount() {
            const selected = getSelectedValues().length;
            const total = state.options.length;
            countEl.textContent = selected + ' / ' + total + ' selected';
        }

        function renderSelected() {
            selectedEl.innerHTML = '';
            const selected = state.options.filter(function (opt) {
                return opt.selected;
            });
            if (!selected.length) {
                selectedEl.classList.add('is-empty');
            } else {
                selectedEl.classList.remove('is-empty');
            }
            selected.forEach(function (opt) {
                const row = document.createElement('div');
                row.className = 'ia-item-picker-selected-item';

                const label = document.createElement('span');
                label.className = 'ia-item-picker-selected-label';
                label.textContent = opt.label;
                label.title = opt.label;

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'ia-item-picker-selected-remove button';
                remove.textContent = 'Remove';
                remove.addEventListener('click', function () {
                    opt.selected = false;
                    onSelectionChanged();
                    renderAll();
                });

                row.appendChild(label);
                row.appendChild(remove);
                selectedEl.appendChild(row);
            });
            updateCount();
        }

        function currentQuery() {
            return (searchEl.value || '').trim().toLowerCase();
        }

        function matchingOptions(query) {
            if (!query) {
                return state.options.slice();
            }
            return state.options.filter(function (opt) {
                return opt.label.toLowerCase().indexOf(query) !== -1;
            });
        }

        function renderList() {
            const query = currentQuery();
            const visible = matchingOptions(query);
            listEl.innerHTML = '';

            if (!visible.length) {
                emptyEl.hidden = false;
                emptyEl.textContent = query
                    ? 'No items match your search.'
                    : 'No pushable items in this item set.';
                selectMatchingBtn.hidden = true;
                return;
            }

            emptyEl.hidden = true;
            if (selectMatchingBtn) {
                if (query) {
                    selectMatchingBtn.hidden = false;
                    selectMatchingBtn.textContent = 'Select matching (' + visible.length + ')';
                } else {
                    selectMatchingBtn.hidden = true;
                }
            }

            visible.forEach(function (opt) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'ia-item-picker-option' + (opt.selected ? ' is-selected' : '');
                button.dataset.value = opt.value;
                button.innerHTML = highlightMatch(opt.label, query);
                button.setAttribute('aria-pressed', opt.selected ? 'true' : 'false');
                button.addEventListener('click', function () {
                    opt.selected = !opt.selected;
                    onSelectionChanged();
                    renderAll();
                });
                listEl.appendChild(button);
            });
        }

        function renderAll() {
            renderSelected();
            renderList();
        }

        function setSelectionFor(values, selected) {
            const lookup = {};
            values.forEach(function (value) {
                lookup[value] = true;
            });
            state.options.forEach(function (opt) {
                if (lookup[opt.value]) {
                    opt.selected = selected;
                }
            });
            onSelectionChanged();
            renderAll();
        }

        if (!select.dataset.pickerInit) {
            select.dataset.pickerInit = '1';
            searchEl.addEventListener('input', renderList);
            selectAllBtn.addEventListener('click', function () {
                state.options.forEach(function (opt) {
                    opt.selected = true;
                });
                onSelectionChanged();
                renderAll();
            });
            if (selectMatchingBtn) {
                selectMatchingBtn.addEventListener('click', function () {
                    const visible = matchingOptions(currentQuery());
                    setSelectionFor(visible.map(function (opt) {
                        return opt.value;
                    }), true);
                });
            }
            clearBtn.addEventListener('click', function () {
                state.options.forEach(function (opt) {
                    opt.selected = false;
                });
                onSelectionChanged();
                renderAll();
            });
        }

        renderAll();
        return state;
    }

    function invalidatePreview() {
        const form = document.getElementById('ia-outbound-push-form');
        if (!form) {
            return;
        }
        const token = form.querySelector('#preview_token');
        if (token) {
            token.value = '';
        }
        form.dataset.previewReady = '0';
        const panel = document.getElementById('ia-outbound-push-confirm-panel');
        if (panel) {
            panel.hidden = true;
        }
        const previews = document.getElementById('ia-outbound-push-previews');
        if (previews) {
            previews.hidden = true;
        }
    }

    function updateConfirmField() {
        const form = document.getElementById('ia-outbound-push-form');
        if (!form) {
            return;
        }
        const threshold = parseInt(form.dataset.typeConfirmThreshold || '10', 10);
        const select = form.querySelector('select.ia-item-picker-native');
        const selectedCount = select && select.iaPickerState
            ? select.iaPickerState.options.filter(function (opt) {
                return opt.selected;
            }).length
            : 0;
        const field = document.getElementById('ia-outbound-confirm-text-field');
        const help = document.getElementById('ia-outbound-confirm-text-help');
        if (!field || !help) {
            return;
        }
        if (selectedCount >= threshold) {
            field.classList.remove('ia-outbound-confirm-hidden');
            help.textContent = 'Required when pushing ' + threshold + ' or more items at once. Type PUSH in uppercase to confirm you intend to overwrite metadata for ' + selectedCount + ' records.';
        } else {
            field.classList.add('ia-outbound-confirm-hidden');
            help.textContent = '';
            const input = field.querySelector('#confirm_text');
            if (input) {
                input.value = '';
            }
        }
    }

    function updateScopeLine(data) {
        const scope = document.getElementById('ia-outbound-push-scope');
        if (!scope) {
            return;
        }
        const count = data.count ?? 0;
        const title = data.item_set_title || '';
        scope.dataset.count = String(count);
        scope.textContent = count + ' item(s) with IA identifiers in item set “' + title + '”.';
    }

    function initItemSetFilter(form) {
        const filter = form.querySelector('#item_set_id, select[name="item_set_id"]');
        if (!filter || filter.type === 'hidden' || filter.dataset.ajaxFilter === '1') {
            return;
        }
        filter.dataset.ajaxFilter = '1';
        const itemsUrl = form.dataset.itemsUrl;
        if (!itemsUrl) {
            return;
        }

        filter.addEventListener('change', function () {
            const itemSetId = filter.value;
            if (!itemSetId) {
                return;
            }
            filter.disabled = true;
            fetch(itemsUrl + '?item_set_id=' + encodeURIComponent(itemSetId), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Failed to load items.');
                    }
                    return response.json();
                })
                .then(function (data) {
                    const picker = document.querySelector('.ia-item-picker');
                    const select = picker?.querySelector('select.ia-item-picker-native');
                    if (!select) {
                        return;
                    }
                    const state = select.iaPickerState || createPickerState(select);
                    setPickerOptions(state, data.items || []);
                    select.iaPickerState = state;
                    const search = picker.querySelector('.ia-item-picker-search');
                    if (search) {
                        search.value = '';
                    }
                    initPicker(picker);
                    updateScopeLine(data);
                    invalidatePreview();
                    updateConfirmField();
                })
                .catch(function () {
                    window.alert('Could not load items for the selected item set.');
                })
                .finally(function () {
                    filter.disabled = false;
                });
        });
    }

    function syncAllPickersForSubmit() {
        document.querySelectorAll('select.ia-item-picker-native').forEach(function (select) {
            if (typeof select.iaSyncPicker === 'function') {
                select.iaSyncPicker();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ia-item-picker').forEach(initPicker);
        const form = document.getElementById('ia-outbound-push-form');
        if (form) {
            initItemSetFilter(form);
            form.addEventListener('submit', syncAllPickersForSubmit);
            updateConfirmField();
        }
    });
})();
