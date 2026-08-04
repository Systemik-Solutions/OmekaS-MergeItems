(function () {
    'use strict';

    document.querySelectorAll('[data-merge-viewport]').forEach(function (viewport) {
        let dragStartX = 0;
        let dragStartScrollLeft = 0;
        let isDragging = false;
        let hasDragged = false;

        viewport.addEventListener('pointerdown', function (event) {
            if (event.button !== 0
                || event.target.closest('a, button, input, label')) {
                return;
            }

            isDragging = true;
            hasDragged = false;
            dragStartX = event.clientX;
            dragStartScrollLeft = viewport.scrollLeft;
            viewport.classList.add('is-dragging');
            viewport.setPointerCapture(event.pointerId);
        });

        viewport.addEventListener('pointermove', function (event) {
            if (isDragging) {
                if (Math.abs(event.clientX - dragStartX) > 5) {
                    hasDragged = true;
                }
                viewport.scrollLeft = dragStartScrollLeft - (event.clientX - dragStartX);
            }
        });

        function stopDragging(event) {
            if (!isDragging) {
                return;
            }
            isDragging = false;
            viewport.classList.remove('is-dragging');
            if (hasDragged) {
                viewport.dataset.mergeJustDragged = 'true';
                setTimeout(function () {
                    delete viewport.dataset.mergeJustDragged;
                }, 0);
            }
            if (viewport.hasPointerCapture(event.pointerId)) {
                viewport.releasePointerCapture(event.pointerId);
            }
        }

        viewport.addEventListener('pointerup', stopDragging);
        viewport.addEventListener('pointercancel', stopDragging);
    });

    document.querySelectorAll('.merge-items-selectable-group').forEach(function (group) {
        const checkbox = group.querySelector('input[type="checkbox"]');
        if (!checkbox) {
            return;
        }

        function updateSelectedState() {
            group.classList.toggle('is-selected', checkbox.checked);
        }

        group.addEventListener('click', function (event) {
            const viewport = group.closest('[data-merge-viewport]');
            if ((viewport && viewport.dataset.mergeJustDragged)
                || event.target.closest('a, button, input, label')) {
                return;
            }
            checkbox.click();
        });

        checkbox.addEventListener('change', updateSelectedState);
        updateSelectedState();
    });

    const carousel = document.querySelector('[data-merge-carousel]');
    if (carousel) {
        const slides = Array.from(carousel.querySelectorAll('[data-merge-slide]'));
        const submit = document.querySelector('[data-merge-submit]');
        const masterOptions = Array.from(
            carousel.querySelectorAll('input[name="master_id"]')
        );

        function updateMasterSelection() {
            const selected = masterOptions.find(function (option) {
                return option.checked;
            });
            slides.forEach(function (slide) {
                const option = slide.querySelector('input[name="master_id"]');
                slide.classList.toggle('is-master', Boolean(option && option.checked));
            });
            if (submit) {
                submit.disabled = !selected;
            }
        }

        masterOptions.forEach(function (option) {
            option.addEventListener('change', updateMasterSelection);
        });
        updateMasterSelection();
    }
}());
