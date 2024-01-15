$(function () {
    /**
     * Configure Popovers
     */
    $('.calendar-popover').popover({
        html: true,
        template: '<div class="popover" role="tooltip"><div class="arrow"></div><h3 class="popover-title"></h3><div class="popover-content"></div></div>'
    });

    /**
     * Dismiss Popovers with Outside Clicks
     */
    $('body').on('click', function (e) {
        $('[data-toggle="popover"]').each(function () {
            if (!$(this).is(e.target) && $(this).has(e.target).length === 0 && $('.popover').has(e.target).length === 0) {
                $(this).popover('hide');
            }
        });
    });

    /**
     * Dependent Field Displays
     */
    $('input, select').change(function () {
        const id = $(this).attr('id');
        let showDependents = false;

        if ((($(this).attr('type') === 'checkbox' || $(this).attr('type') === 'radio')) && $(this).is(':checked')) {
            showDependents = true;
        }

        if ($(this).attr('type') === 'number' && $(this).val() && parseInt($(this).val(), 10) > 0) {
            showDependents = true;
        }

        if ($(this).is('select') && $(this).val() === 'paid') {
            showDependents = true;
        }

        const dependsOnCurrentField = $('[data-depends-on-field=' + id + ']');
        if (showDependents) {
            dependsOnCurrentField.each(function () {
                if ($(this).attr('data-dependent-required') === '1') {
                    $(this).closest('.form-group').addClass('required');
                    $(this).attr('required', true);
                }
            });
            dependsOnCurrentField.show();
        } else {
            dependsOnCurrentField.each(function () {
                if ($(this).attr('data-dependent-required') === '1') {
                    $(this).closest('.form-group').removeClass('required');
                    $(this).removeAttr('required');
                }
            });
            dependsOnCurrentField.hide();
        }

        const hiddenByCurrentField = $('[data-hidden-on-field=' + id + ']');
        if (showDependents) {
            hiddenByCurrentField.each(function () {
                if ($(this).attr('data-dependent-required') === '1') {
                    $(this).closest('.form-group').removeClass('required');
                    $(this).removeAttr('required');
                }
            });
            hiddenByCurrentField.hide();
        } else {
            hiddenByCurrentField.each(function () {
                if ($(this).attr('data-dependent-required') === '1') {
                    $(this).closest('.form-group').addClass('required');
                    $(this).attr('required', true);
                }
            });
            hiddenByCurrentField.show();
        }
    });
    $('input, select').change();

    /**
     * Calender Event Submissions
     */
    if ($('.events.add').length || $('.events.edit').length) {
        // Prerequisites require attendees to be DMS members
        $('#requires-prerequisite-id').change(function () {
            if ($(this).val()) {
                $('#members-only').attr('checked', true);
            }
        });
        $('#members-only').change(function () {
            if (!$(this).is(':checked')) {
                $('#requires-prerequisite-id').val('');
            }
        });

        // Digest configuration values from template
        const minLead = parseInt($('#config-mininum-booking-lead-time').text(), 10);
        const maxLead = parseInt($('#config-maximum-booking-lead-time').text(), 10);

        if ($('#unlockedEdit').length) {
            $('#event-start, #event-end, #event-start-2, #event-end-2, #event-start-3, #event-end-3, #event-start-4, #event-end-4, #event-start-5, #event-end-5').each(function () {
                $(this).datetimepicker({
                    useCurrent: false,
                    //minDate: moment().add(minLead, 'days'),
                    //maxDate: moment().add(maxLead, 'days'),
                    date: new Date(
                        Date.parse(
                            $(this).val(),
                            "mm/dd/yyyy hh:MM tt"
                        )
                    )
                });
            });
        } else {
            $('#event-start, #event-end, #event-start-2, #event-end-2, #event-start-3, #event-end-3, #event-start-4, #event-end-4, #event-start-5, #event-end-5').each(function () {
                $(this).datetimepicker({
                    useCurrent: false,
                    minDate: moment().add(minLead, 'days'),
                    maxDate: moment().add(maxLead, 'days'),
                    date: new Date(
                        Date.parse(
                            $(this).val(),
                            "mm/dd/yyyy hh:MM tt"
                        )
                    )
                });
            });
        }
        const eventStart = $('#event-start');
        const eventEnd = $('#event-end');
        const eventStart2 = $('#event-start-2');
        const eventEnd2 = $('#event-end-2');
        const eventStart3 = $('#event-start-3');
        const eventEnd3 = $('#event-end-3');
        const eventStart4 = $('#event-start-4');
        const eventEnd4 = $('#event-end-4');
        const eventStart5 = $('#event-start-5');
        const eventEnd5 = $('#event-end-5');

        eventStart.on('dp.change', function (e) {
            if (e.oldDate === null) {
                new Date(e.date._d.setHours(12, 0, 0));
                $(this).data('DateTimePicker').date(e.date.add(0, 'h'));
            }
        });
        eventStart.on('dp.change', function (e) {
            eventEnd.data('DateTimePicker').minDate(e.date);
            eventEnd.data('DateTimePicker').date(e.date.add(1, 'h'));
        });
        eventEnd.on('dp.change', function (e) {
            //$('#event-start').data('DateTimePicker').maxDate(e.date);
            eventStart2.data('DateTimePicker').minDate(e.date);
        });
        eventStart2.on('dp.change', function (e) {
            eventEnd.data('DateTimePicker').maxDate(e.date);
            eventEnd2.data('DateTimePicker').date(e.date.add(1, 'h'));
            eventEnd2.data('DateTimePicker').minDate(e.date);
        });
        eventEnd2.on('dp.change', function (e) {
            //$('#event-start-2').data('DateTimePicker').maxDate(e.date);
            eventStart3.data('DateTimePicker').minDate(e.date);
        });
        eventStart3.on('dp.change', function (e) {
            eventEnd2.data('DateTimePicker').maxDate(e.date);
            eventEnd3.data('DateTimePicker').date(e.date.add(1, 'h'));
            eventEnd3.data('DateTimePicker').minDate(e.date);
        });
        eventEnd3.on('dp.change', function (e) {
            //$('#event-start-3').data('DateTimePicker').maxDate(e.date);
            eventStart4.data('DateTimePicker').minDate(e.date);
        });
        eventStart4.on('dp.change', function (e) {
            eventEnd3.data('DateTimePicker').maxDate(e.date);
            eventEnd4.data('DateTimePicker').date(e.date.add(1, 'h'));
            eventEnd4.data('DateTimePicker').minDate(e.date);
        });
        eventEnd4.on('dp.change', function (e) {
            //$('#event-start-4').data('DateTimePicker').maxDate(e.date);
            eventStart5.data('DateTimePicker').minDate(e.date);
        });
        eventStart5.on('dp.change', function (e) {
            eventEnd4.data('DateTimePicker').maxDate(e.date);
            eventEnd5.data('DateTimePicker').date(e.date.add(1, 'h'));
            eventEnd5.data('DateTimePicker').minDate(e.date);
        });

        $('.payment-type-select').change(function (e) {
            $('.event-cost, .event-eventbrite').addClass('hidden');
            const $costField = $('#cost');
            const $paidSpacesField = $('#paid-spaces');
            const $eventbriteLinkField = $('#eventbrite-link');

            $costField.val(0);
            $costField.trigger('change');

            // We reset paid spaces to ensure that they aren't sent with the form/added. Really this check should happen on the backend.
            $paidSpacesField.val(0);
            $paidSpacesField.trigger('change');

            $eventbriteLinkField.val('');
            $eventbriteLinkField.trigger('change');

            if ($(this).val() === 'paid') {
                $('.event-cost').removeClass('hidden');
            }

            if ($(this).val() === 'eventbrite') {
                $('.event-eventbrite').removeClass('hidden');
            }
        });
    }

    /**
     * Setup dropdown filters
     */
    let ddFilterList = document.getElementsByClassName('dropdown-filter');
    for (let ddElement of ddFilterList) {
        let textElement = document.createElement('input');
        textElement.setAttribute('type', 'text');
        textElement.setAttribute('placeholder', 'Type to filter ...');
        // Filter list items on keyup event
        textElement.addEventListener('keyup', (e) => {
            let searchTerm = e.target.value.toLowerCase();
            let parentUL = e.target.parentElement;
            let listItems = parentUL.getElementsByTagName("li");
            for (let item of listItems) {
                if (item.innerText.toLowerCase().includes(searchTerm)) {
                    item.style.display = "";
                } else {
                    item.style.display = "none";
                }
            }
        });
        // Attach filter to dropdown
        ddElement.insertBefore(textElement, ddElement.firstChild);
    }

    /**
     * Disable submit button when a form is submitted
     */
    $("form").submit(function () {
        // disable submit button
        $(":submit", this).attr("disabled", "disabled");
        // set text to "Working..."
        $(":submit", this).text("Working...");
    });


    /**
     * Helper functions for event preview
     */
    $("#eventPreview a").on("click", function (e) {
        var sourceForm = document.getElementById('eventDetails');
        var targetForm = document.getElementById('eventPreview');

        Array.from(sourceForm.elements).forEach(function(element) {
            if (element.name && element.value && (element.type !== 'hidden' || element.name === 'config')) {
                console.log('Copying field', element.name, element.value);
                var targetElement = targetForm.elements[element.name];
                var targetElementPreview = targetForm.elements[element.name + '_preview'];
                if (targetElement) {
                    if (element.type === 'select-one') {
                        // Set the item text
                        let selectedText = element.options[element.selectedIndex].text;
                        targetElement.setAttribute('maxlength', selectedText.length);
                        targetElement.value = selectedText;
                    } else if (element.type === 'select-multiple') {
                        targetElement.value = getSelectedOptions(element);
                    } else {
                        targetElement.value = element.value;
                    }
                } else if (targetElementPreview) {
                    if (element.type === 'select-one') {
                        // Set the item text
                        let selectedText = element.options[element.selectedIndex].text;
                        targetElementPreview.setAttribute('maxlength', selectedText.length);
                        targetElementPreview.value = selectedText;
                    } else {
                        targetElementPreview.value = element.value;
                    }
                }
            }
        });
        targetForm.submit();
    });

    function getSelectedOptions(select) {
        return [...select.selectedOptions].map(el => el.value + ':' + el.text).join(';')
    }

});
