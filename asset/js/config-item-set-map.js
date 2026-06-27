(function () {
    'use strict';

    function buildMapJson(rows) {
        const map = {};
        rows.forEach(function (row) {
            const itemSetId = row.querySelector('.ia-outbound-map-item-set')?.value;
            const collection = row.querySelector('.ia-outbound-map-collection')?.value?.trim();
            if (itemSetId && collection) {
                map[itemSetId] = collection;
            }
        });
        return JSON.stringify(map);
    }

    function updateDisabledOptions(table) {
        const selected = {};
        table.querySelectorAll('.ia-outbound-map-item-set').forEach(function (select) {
            if (select.value) {
                selected[select.value] = true;
            }
        });
        table.querySelectorAll('.ia-outbound-map-item-set').forEach(function (select) {
            Array.from(select.options).forEach(function (option) {
                if (!option.value) {
                    return;
                }
                option.disabled = !!selected[option.value] && option.value !== select.value;
            });
        });
    }

    function createRow(table, itemSets, itemSetId, collection) {
        const tbody = table.querySelector('tbody');
        const tr = document.createElement('tr');
        tr.className = 'ia-outbound-map-row';

        const itemSetCell = document.createElement('td');
        const itemSetSelect = document.createElement('select');
        itemSetSelect.className = 'ia-outbound-map-item-set';
        itemSetSelect.required = true;

        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = '— Select item set —';
        itemSetSelect.appendChild(emptyOption);

        itemSets.forEach(function (set) {
            const option = document.createElement('option');
            option.value = String(set.id);
            option.textContent = set.title;
            if (String(set.id) === String(itemSetId)) {
                option.selected = true;
            }
            itemSetSelect.appendChild(option);
        });
        itemSetCell.appendChild(itemSetSelect);

        const collectionCell = document.createElement('td');
        const collectionInput = document.createElement('input');
        collectionInput.type = 'text';
        collectionInput.className = 'ia-outbound-map-collection';
        collectionInput.placeholder = 'IA collection identifier';
        collectionInput.value = collection || '';
        collectionInput.required = true;
        collectionCell.appendChild(collectionInput);

        const actionCell = document.createElement('td');
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'button ia-outbound-map-remove';
        removeBtn.textContent = 'Remove';
        actionCell.appendChild(removeBtn);

        tr.appendChild(itemSetCell);
        tr.appendChild(collectionCell);
        tr.appendChild(actionCell);
        tbody.appendChild(tr);

        itemSetSelect.addEventListener('change', function () {
            updateDisabledOptions(table);
        });
        removeBtn.addEventListener('click', function () {
            tr.remove();
            updateDisabledOptions(table);
            syncEmptyState(table);
        });

        updateDisabledOptions(table);
        syncEmptyState(table);
    }

    function syncEmptyState(table) {
        const panel = table.closest('.ia-outbound-item-set-map');
        const empty = panel?.querySelector('.ia-outbound-item-set-map-empty');
        const hasRows = table.querySelectorAll('.ia-outbound-map-row').length > 0;
        if (empty) {
            empty.hidden = hasRows;
        }
    }

    function initPanel(panel) {
        if (panel.dataset.mapInit === '1') {
            return;
        }
        panel.dataset.mapInit = '1';

        const itemSets = JSON.parse(panel.dataset.itemSets || '[]');
        const initialMap = JSON.parse(panel.dataset.initialMap || '{}');
        const table = panel.querySelector('.ia-outbound-item-set-map-table');
        const hidden = panel.querySelector('#item_set_collection_map_json');
        const addBtn = panel.querySelector('.ia-outbound-map-add');
        const form = panel.closest('form');

        Object.keys(initialMap).forEach(function (itemSetId) {
            createRow(table, itemSets, itemSetId, initialMap[itemSetId]);
        });
        if (!Object.keys(initialMap).length) {
            createRow(table, itemSets, '', '');
        }

        addBtn.addEventListener('click', function () {
            createRow(table, itemSets, '', '');
        });

        if (form) {
            form.addEventListener('submit', function () {
                if (hidden) {
                    hidden.value = buildMapJson(
                        Array.from(table.querySelectorAll('.ia-outbound-map-row'))
                    );
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ia-outbound-item-set-map').forEach(initPanel);
    });
})();
