(function () {
    'use strict';

    var script = document.currentScript;
    var actionUrl = script ? script.dataset.mergeItemsAction : '';
    var actionLabel = script ? script.dataset.mergeItemsLabel : 'Merge selected';
    var goLabel = script ? script.dataset.goLabel : 'Go';
    var batchForm = document.getElementById('batch-form');

    if (!actionUrl || !batchForm) {
        return;
    }

    var actionSelect = batchForm.querySelector('.batch-actions-select');
    var actionButtons = batchForm.querySelector('.batch-actions');
    if (!actionSelect || !actionButtons || actionSelect.querySelector('[value="merge-selected"]')) {
        return;
    }

    var option = document.createElement('option');
    option.value = 'merge-selected';
    option.textContent = actionLabel;
    option.disabled = true;

    var updateSelected = actionSelect.querySelector('[value="update-selected"]');
    if (updateSelected) {
        updateSelected.insertAdjacentElement('afterend', option);
    } else {
        actionSelect.appendChild(option);
    }

    var button = document.createElement('input');
    button.type = 'submit';
    button.className = 'merge-selected';
    button.name = 'merge_selected';
    button.value = goLabel;
    button.formAction = actionUrl;
    actionButtons.appendChild(button);

    function updateAvailability() {
        option.disabled = batchForm.querySelectorAll(
            '.batch-edit td input[name="resource_ids[]"]:checked'
        ).length < 2;
    }

    batchForm.addEventListener('change', function (event) {
        if (event.target.matches('.select-all, input[name="resource_ids[]"]')) {
            updateAvailability();
        }
    });

    updateAvailability();
}());
