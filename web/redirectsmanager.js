var $table    = $('.js-redirectsmanager-table, .js-new-redirects-table');
var $blankRow = $table.find('.js-blank-spacer').first().clone(false, false);

$blankRow.find('.js-button').filter(function () {
    "use strict";

    return ($(this).data('type') !== 'cancel');
}).removeClass('is-hidden');

var showSaveAlert = function () {
    "use strict";

    var $alertHtml = $('<div class="alert alert-info alert-dismissible is-hidden js-save-alert" style="opacity: 0;"><button type="button" class="close" data-dismiss="alert">×</button>Don\'t forget to press the save button (including before changing pages)!</div>');

    $table.filter(function () {
        return ($(this).hasClass('js-new-redirects-table'));
    }).before($alertHtml);
    $alertHtml.animate({opacity: 1}, 500);
};

$table.on('click', '.js-button', function (e) {
    "use strict";

    e.preventDefault();

    var $row = $(this).parents('tr');
    var type = $(this).data('type');

    if (type === 'delete' && window.confirm('Are you sure you want to delete this row?')) {
        $row.animate({opacity: 0}, 500, 'linear', function () {
            setTimeout(function () {
                var $parent = $row.parents('.js-new-redirects-table');

                $row.hide();
                $row.find('.js-delete').val(1);

                if (!$parent.find('tbody tr').length) {
                    $parent.find('tbody').append($blankRow).find('.js-button').addClass('is-hidden');
                }
            }, 200);
        });

        showSaveAlert();
    } else if (type === 'edit') {
        if ($row.hasClass('is-editing')) {
            var isEmpty = false;

            $row.find('.js-input').each(function () {
                if ((this.type === 'text' && $(this).val() === '') || (this.type !== 'text' && !$(this).find('option:selected').length)) {
                    isEmpty = true;

                    this.setCustomValidity('This field cannot be empty');
                } else {
                    this.setCustomValidity('');
                }
            });

            if (!isEmpty) {
                $row.removeClass('is-editing').find('.js-input').hide().end().find('.js-text').show();
                $row.find('button[data-type="cancel"]').addClass('is-hidden');

                $row.find('.js-input').each(function () {
                    $(this).prev('.js-text').text($(this).val());
                });

                showSaveAlert();
            }
        } else {
            $row.find('.js-input').each(function () {
                if (this.type === 'text') {
                    $(this).data('original-value', $(this).val());
                } else {
                    $(this).data('original-value', $(this).find('option:selected').val());
                }
            });

            $row.addClass('is-editing').find('.js-text').hide().end().find('.js-input').show();
            $row.find('button[data-type="cancel"]').removeClass('is-hidden');
        }
    } else if (type === 'cancel') {
        $row.find('.js-input').each(function () {
            var value = $(this).data('original-value');

            if (this.type === 'text') {
                $(this).val(value);
                this.setCustomValidity('');
            } else {
                $(this).find('option:selected').removeAttr('selected');
                $(this).find('option').filter(function () {
                    return ($(this).val() === value);
                }).attr('selected', true);
                this.setCustomValidity('');
            }

            $(this).removeData('original-value');
        });

        $row.removeClass('is-editing').find('.js-input').hide().end().find('.js-text').show();
        $row.find('button[data-type="cancel"]').addClass('is-hidden');
    }

    return false;
});

$('.js-add-redirect').on('click', function (e) {
    "use strict";

    e.preventDefault();

    var isEmpty = false;

    $(this).parent().find('.js-add-input').each(function () {
        var value = $.trim($(this).val());

        if (value !== '' && !isEmpty) {
            this.setCustomValidity('');

            if (this.type === 'text') {
                if (value.indexOf('/') !== 0) {
                    value = '/' + value;
                }

                $blankRow.find('[name="' + $(this).data('add-to') + '"]').val(value).prev('.js-text').text(value);
            } else {
                $blankRow.find('[name="' + $(this).data('add-to') + '"] option').removeAttr('selected').filter(function () {
                    return ($(this).val() === value);
                }).attr('selected', true).parent().prev('.js-text').text(value);
            }
        } else {
            this.setCustomValidity('This field cannot be empty');

            isEmpty = true;

            return false;
        }
    });

    if (!isEmpty) {
        $(this).parent().find('.js-add-input').each(function () {
            if (this.type === 'text') {
                $(this).val('');
            } else {
                $(this).find('option:selected').removeAttr('selected');
            }
        });

        if ($table.find('.js-blank-spacer').length) {
            $table.find('.js-blank-spacer').remove();
        }

        $table.filter(function () {
            return ($(this).hasClass('js-new-redirects-table'));
        }).find('tbody').append($blankRow);
    }

    return false;
});

$('.js-submit-changes').on('click', function (e) {
    "use strict";

    e.preventDefault();

    $('.js-redirectsmanager-form').trigger('submit');

    return false;
});
