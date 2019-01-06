"use strict";

(function ($, URL) {
    $.fn.fileInputWidget =
        function (options) {
            options = $.extend({}, {
                accept : null,
                limit : null,
                inputName : null
            }, options);

            if (!options.inputName) {
                throw 'требуется имя поля inputName';
            }

            return this.each(function () {
                const $block = $(this);
                const $btnAdd = $('.add', $block);

                function reindex() {
                    const $files = $('.file', $block);
                    $files.each(function (pos, $item) {
                        $('input', $item).attr('name', options.inputName + '[' + pos + ']');
                    });
                    $btnAdd.toggle(!options.limit || options.limit < 1 || $files.length < options.limit);
                }

                $block.on('change', '.file [type="file"]', function () {
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
                }).on('click', '.file .del', function () {
                    const $file = $(this).closest('.file');
                    const url = $file.data('url');

                    if (url) {
                        URL.revokeObjectURL(url);
                    }

                    $file.remove();
                    reindex();
                }).on('sortupdate', function () {
                    reindex();
                }).sortable({
                    items : '.file'
                });

                $btnAdd.on('change', '[type="file"]', function () {
                    const input = this;
                    if (input.files.length < 1) {
                        return;
                    }

                    const fileInputId = 'file-input-widget-addinput' + Date.now();
                    const $fileInput = $(input).clone().attr('id', fileInputId);

                    $('<label></label>', {
                        'class' : 'file btn',
                        'for' : fileInputId,
                    }).append($fileInput, $('<img/>'), $('<div class="name"></div>'),
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
