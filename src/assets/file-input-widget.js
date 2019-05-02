"use strict";

(function ($, URL) {

    if (typeof($.fn.fileInputWidget) == 'function') {
        return;
    }

    $.fn.fileInputWidget = function (options) {
        options = $.extend({}, {
            layout: 'horizontal',
            limit : 0,
            accept : null,
            removeExt: false,
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
                // элементы файлов
                const $files = $('.file', $widget);

                // обхдим все элементы
                $files.each(function (pos, $item) {
                    // устанавливаем имя ввода файла с индексом
                    $('input', $item).attr('name', options.inputName + '[' + pos + ']');
                });

                // отображаем/скрываем кнопку при достижении лимита
                $btnAdd.css('display', !options.limit || options.limit < 1 || $files.length < options.limit ? 'flex' : 'none');
            }

            // добавление файла
            $btnAdd.on('change', '[type="file"]', function () {

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
                const file = $fileInput[0].files[0];

                // получаем URL картинки
                const url = file.type.match(/^image/) ? URL.createObjectURL(file) : null;

                // готовим картинку
                const $img = $('<img/>', {'class': 'image', src: url});

                // создаем новый элемент файла
                $('<div class="file"></div>').append(
                    // файл
                    $fileInput,

                    // каринка
                    $('<a></a>', { 'class': 'download', href: url, download: file.name }).append(
                        options.layout == 'horizontal' ?
                            $('<img/>', {'class': 'image', src: url}) :
                            $('<i class="image fa fas fa-download"></i>')
                    ),

                    // имя файла
                    $('<a></a>', { 'class': 'name', href: url, download: file.name, text: file.name }),

                    // кнопка удаления
                    $('<button class="del btn btn-link text-danger" title="Удалить">&times;</button>')
                ).insertBefore($btnAdd);

                // переиндексируем имена
                reindex();
            });

            // удаление файла
            $widget.on('click', '.file .del', function () {
                const $file = $(this).closest('.file');

                // освобождаем ресурс URL
                const url = $('img', $file).attr('src');
                if (url) {
                    URL.revokeObjectURL(url);
                }

                // удаляем элемент
                $file.remove();

                // переиндексируем имена полей ввода
                reindex();
            });

            // сортировка файлов
            $widget.sortable({
                items : '.file',
                update: reindex
            });

            // задаем начальные имена
            reindex();
        });
    }
})(jQuery, window.URL);
