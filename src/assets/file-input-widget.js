/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.04.20 20:12:29
 */

(function (window, $) {
    "use strict";

    // noinspection JSUnresolvedVariable
    if (typeof $.fn.fileInputWidget === 'function') {
        return;
    }

    /**
     * Плагин jQuery
     *
     * @param {object} options
     * @returns {jQuery}
     */
    $.fn.fileInputWidget = function (options) {
        // noinspection AssignmentToFunctionParameterJS,JSUnusedGlobalSymbols
        options = $.extend({}, {
            layout: 'images',
            limit: 0,
            accept: null,
            removeExt: false,
            inputName: null,
            messages: {}
        }, options);

        // noinspection JSUnresolvedVariable
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
            function reindex()
            {
                // элементы файлов
                const $files = $('.file', $widget);

                // обходим все элементы
                $files.each(function (pos, $item) {
                    // устанавливаем имя ввода файла с индексом
                    // noinspection JSUnresolvedVariable
                    $('input', $item).attr('name', options.inputName + '[' + pos + ']');
                });

                // отображаем/скрываем кнопку при достижении лимита
                // noinspection JSUnresolvedVariable
                $btnAdd.css('display', !options.limit || options.limit < 1 || $files.length < options.limit ? 'flex' : 'none');
            }

            // добавление файла
            $btnAdd.on('change', '[type="file"]', function () {
                // noinspection JSUnresolvedVariable
                if (this.files.length < 1) {
                    return;
                }

                // клонируем элемент ввода с выбранным файлом
                const $fileInput = $(this).clone();

                // удаляем id у копии
                $fileInput.removeAttr('id');

                // сбрасываем файл у элемента кнопки
                $(this).val('');

                // получаем файл
                // noinspection JSUnresolvedVariable
                const file = $fileInput[0].files[0];

                // получаем URL картинки
                const url = window.URL.createObjectURL(file);

                // создаем новый элемент файла
                // noinspection RequiredAttributes,JSUnresolvedVariable,JSUnusedGlobalSymbols
                $('<div></div>', {'class': 'file', data: {url: url}}).append(
                    // файл
                    $fileInput,

                    // картинка
                    $('<a></a>', {'class': 'download', href: url, download: file.name}).append(
                        options.layout === 'images' ?
                            $('<img/>', {'class': 'image', src: file.type.match(/^image/) ? url : null, alt: ''}) :
                            $('<i class="image fa fas fa-download"></i>')
                    ),

                    // имя файла
                    options.layout === 'images' ? '' : $('<a></a>', {
                        'class': 'name',
                        href: url,
                        download: file.name,
                        text: file.name
                    }),

                    // кнопка удаления
                    $(`<button class="del btn btn-link text-danger" title="${options.messages['Удалить'] || 'Удалить'}">&times;</button>`)
                ).insertBefore($btnAdd);

                // переиндексируем имена
                reindex();
            });

            // удаление файла
            $widget.on('click', '.file .del', function () {
                const $file = $(this).closest('.file');

                // освобождаем ресурс URL
                const url = $file.data('url');
                if (url) {
                    window.URL.revokeObjectURL(url);
                }

                // удаляем элемент
                $file.remove();

                // переиндексируем имена полей ввода
                reindex();
            });

            // сортировка файлов
            // noinspection JSUnresolvedFunction,JSUnusedGlobalSymbols
            $widget.sortable({
                items: '.file',
                update: reindex
            });

            // задаем начальные имена
            reindex();
        });
    };
})(window, jQuery);
