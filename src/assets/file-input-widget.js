"use strict";

(function ($, URL) {

    if (typeof($.fn.fileInputWidget) == 'function') {
        return;
    }

    $.fn.fileInputWidget = function (options) {
        options = $.extend({}, {
            accept : null,
            limit : null,
            inputName : null
        }, options);

        if (!options.inputName) {
            throw 'требуется имя поля inputName';
        }

        return this.each(function () {
            const $widget = $(this);
            const $btnAdd = $('.add', $widget);

            if ($widget.data('file-input-widget')) {
                return;
            }

            $widget.data('file-input-widget', true);

            /**
             * Переиндексация имен полей формы
             */
            function reindex() {
                const $files = $('.file', $widget);

                $files.each(function (pos, $item) {
                    $('input', $item).attr('name', options.inputName + '[' + pos + ']');
                });

                $btnAdd.toggle(!options.limit || options.limit < 1 || $files.length < options.limit);
            }

            $widget.on('change', '.file [type="file"]', function () {
                const input = this;

                if (input.files.length < 1) {
                    return;
                }

                const $file = $(input).closest('.file');
                const url = input.files[0].type.match(/^image/) ? URL.createObjectURL(input.files[0]) : null;
                $file.data('url', url);

                const $img = $('img', $file);
                if (url) {
                    $img.attr('src', url);
                } else {
                    $img.removeAttr('src');
                }

                $('.name', $file).text(input.files[0].name);
            });

            $widget.on('click', '.file .del', function () {
                const $file = $(this).closest('.file');
                const url = $file.data('url');

                if (url) {
                    URL.revokeObjectURL(url);
                }

                $file.remove();
                reindex();
            });

            $widget.on('sortupdate', function () {
                reindex();
            });

            $widget.sortable({
                items : '.file'
            });

            $btnAdd.on('change', '[type="file"]', function () {
                const input = this;
                if (input.files.length < 1) {
                    return;
                }

                const fileId = 'file-input-widget-addinput' + Date.now();

                const $fileInput = $(input).clone().attr('id', fileId);

                $('<label></label>', {
                    'class' : 'file btn',
                    'for' : fileId,
                }).append(
                    $fileInput,
                    $('<img class="img"/>'),
                    $('<div class="name"></div>'),
                    $('<button class="del btn btn-link text-danger" title="удалить">&times;</button>')
                ).insertBefore($btnAdd);

                reindex();

                $fileInput.trigger('change');

                $(input).val('');
            });

            reindex();
        });
    }
})(jQuery, window.URL);
