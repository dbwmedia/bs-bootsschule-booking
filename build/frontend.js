/**
 * BS Bootsschule Booking - date selection and add to cart
 */
(function () {
    var root = document.querySelector('.bs-bootsschule-booking');
    var config = window.bsBookingFrontend;
    if (!root || !config) return;

    var button = root.querySelector('.bs-add-cart');
    var messages = root.querySelector('.bs-messages');
    var courses = root.querySelectorAll('.bs-template');

    function firstMissingCourse() {
        for (var i = 0; i < courses.length; i++) {
            if (!courses[i].querySelector('input[type="radio"]:checked')) {
                return courses[i].dataset.courseTitle;
            }
        }
        return null;
    }

    function validate() {
        var missing = firstMissingCourse();
        button.disabled = missing !== null;
        button.textContent = missing === null ? config.i18n.addToCart : config.i18n.chooseFor.replace('%s', missing);
    }

    /**
     * Dates of other courses that share a day with the current selection
     * cannot be chosen (e.g. SBF Binnen and SBF See on the same weekend).
     */
    function updateOverlaps() {
        courses.forEach(function (course) {
            var blocked = {};
            courses.forEach(function (other) {
                if (other === course) return;
                var selected = other.querySelector('input[type="radio"]:checked');
                if (!selected) return;
                selected.dataset.days.split(',').forEach(function (day) {
                    blocked[day] = other.dataset.courseTitle;
                });
            });

            course.querySelectorAll('input[type="radio"]').forEach(function (input) {
                if (input.dataset.full) return;
                var label = input.closest('label');
                var conflict = null;
                input.dataset.days.split(',').forEach(function (day) {
                    if (blocked[day]) conflict = blocked[day];
                });

                input.disabled = conflict !== null;
                label.classList.toggle('disabled', conflict !== null);
                var note = label.querySelector('.bs-badge--overlap');
                if (conflict && !note) {
                    note = document.createElement('small');
                    note.className = 'bs-badge bs-badge--overlap';
                    label.querySelector('.bs-badges').appendChild(note);
                }
                if (note) {
                    if (conflict) note.textContent = config.i18n.overlap.replace('%s', conflict);
                    else note.remove();
                }
            });
        });
    }

    function showError(message) {
        messages.textContent = message;
        messages.classList.add('error');
    }

    root.addEventListener('change', function (event) {
        if (event.target.matches('input[type="radio"]')) {
            messages.textContent = '';
            messages.classList.remove('error');
            updateOverlaps();
            validate();
        }
    });

    button.addEventListener('click', function () {
        var selection = {};
        courses.forEach(function (course) {
            var input = course.querySelector('input[type="radio"]:checked');
            if (input) selection[course.dataset.courseIndex] = input.value;
        });

        button.disabled = true;
        button.textContent = config.i18n.adding;

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'bs_bootsschule_add_cart',
                product_id: config.productId,
                selection: JSON.stringify(selection),
                _ajax_nonce: config.nonce
            })
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location = data.data.cart_url;
                    return;
                }
                showError((data.data && data.data.message) || config.i18n.serverError);
                validate();
            })
            .catch(function () {
                showError(config.i18n.serverError);
                validate();
            });
    });

    validate();
})();
