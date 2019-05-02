<?php
namespace dicr\file;

use yii\base\InvalidConfigException;
use yii\helpers\Html;
use yii\helpers\ArrayHelper;

/**
 * Виджет ввода картинок.
 *
 * Использовать в проекте для создания класса FileInputWidget от нужного пакета Bootstrap:
 *
 * class FileInputWidget extends \yii\bootstrap(4?)\InputWidget
 * {
 *   use FileInputWidgetTrait;
 * }
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
trait FileInputWidgetTrait
{
    /** @var string вид 'horizontal' или 'vertical' */
    public $layout = 'horizontal';

    /** @var int|null максимальное кол-во файлов */
    public $limit;

    /** @var string|null mime-типы в input type=file, например image/* */
    public $accept;

    /** @var bool|null удалять расширение файла при отображении (default true for horizontal) */
    public $removeExt;

    /** @var string|null название поля формы аттрибута */
    public $inputName;

    /** @var \dicr\file\AbstractFile[]|null файлы */
    public $value;

    /**
     * {@inheritdoc}
     * @see \yii\widgets\InputWidget::init()
     */
    public function init()
    {
        parent::init();

        if (! isset($this->model)) {
            throw new InvalidConfigException('model');
        }

        if (! isset($this->attribute)) {
            throw new InvalidConfigException('attribute');
        }

        // удаляем индекс массива в конце
        $this->attribute = preg_replace('~\[\]$~uism', '', $this->attribute);

        if (!in_array($this->layout, ['horizontal', 'vertical'])) {
            throw new InvalidConfigException('layout');
        }

        if (!isset($this->removeExt)) {
            $this->removeExt = $this->layout == 'horizontal';
        }

        // получаем название поля ввода файлов
        if (!isset($this->inputName)) {
            $this->inputName = Html::getInputName($this->model, $this->attribute);
        }

        // получаем файлы
        if (!isset($this->value)) {
            $this->value = Html::getAttributeValue($this->model, $this->attribute);
        }

        if (empty($this->value)) {
            $this->value = [];
        } elseif (! is_array($this->value)) {
            $this->value = [$this->value]; // нельзя применять (array) потому как File::toArray
        } elseif ($this->limit > 0) {
            ksort($this->value);
            $this->value = array_slice($this->value, 0, $this->limit, true);
        }

        // добавляем enctype форме
        $this->field->form->options['enctype'] = 'multipart/form-data';

        // отключаем валидацию на стороне клиента
        $this->field->enableClientValidation = false;

        // добавляем нужные классы
        Html::addCssClass($this->options, 'file-input-widget');
        Html::addCssClass($this->options, 'layout-' . $this->layout);

        // регистрируем ассет
        $this->view->registerAssetBundle(FileInputWidgetAsset::class);

        // добавляем опции клиенту
        $this->clientOptions = ArrayHelper::merge([
            'layout' => $this->layout,
            'limit' => $this->limit,
            'accept' => $this->accept,
            'removeExt' => $this->removeExt,
            'inputName' => $this->inputName
        ], $this->clientOptions);

        // регистрируем плагин
        $this->registerPlugin('fileInputWidget');
    }

    /**
     * Рендерит картинку
     *
     * @return string
     */
    protected function renderImage(StoreFile $file)
    {
        $img = null;

        if ($this->layout == 'horizontal') {
            $img = Html::img(preg_match('~^image\/.+~uism', $file->mimeType) ? $file->url : null, [
                'alt' => '',
                'class' => 'image'
            ]);
        } else {
            $img = Html::tag('i', '', [ 'class' => 'image fa fas fa-download']);
        }

        return Html::a($img, $file->url, [
            'class' => 'download',
            'download' => $file->name
        ]);
    }

    /**
     * Рендерит файл
     *
     * @param int $pos
     * @param StoreFile $file
     * @return string
     */
    protected function renderFileBlock(int $pos, StoreFile $file)
    {
        return Html::tag('div',
            // $_POST - параметр с именем старого файла
            Html::hiddenInput($this->inputName . '[' . $pos . ']', $file->name) .

            // картинка
            $this->renderImage($file) .

            // имя файла
            Html::a(
                $file->getName([
                    'removePrefix' => 1,
                    'removeExt' => $this->removeExt
                ]),

                $file->url,

                [
                    'class' => 'name',
                    'download' => $file->name
                ]
            ) .

            // кнопка удаления файла
            Html::button('&times;', [
                'class' => 'del btn btn-link text-danger',
                'title' => 'Удалить'
            ]),

            [
                'class' => 'file'
            ]
        );
    }

    /**
     * Рендерит блок файлов
     *
     * @return string
     */
    protected function renderFiles()
    {
        $content = '';

        foreach ($this->value as $pos => $file) {
            if ($file instanceof StoreFile) {
                $content .= $this->renderFileBlock((int)$pos, $file);
            }
        }

        return $content;
    }

    /**
     * Рендерит кнопку добавления картинки
     *
     * @return string
     */
    protected function renderAddButton()
    {
        // id поля файла
        $fileId = $this->id . '-addinput-' . rand(1, 999999);

        return Html::label(

            // $_FILES параметр файла
            Html::fileInput(null, null, [
                'accept' => $this->accept ?: null,
                'id' => $fileId
            ]) .

            // значек кнопки
            Html::tag('i', '', [
                'class' => 'fa fas fa-plus-circle text-success'
            ]),

            $fileId,

            [
                'class' => 'add',
                'title' => 'Добавить файл',
                'style' => [
                    'display' => $this->limit > 0 && count($this->value) >= $this->limit ? 'none' : 'flex'
                ]
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\Widget::render()
     */
    public function run()
    {
        return Html::tag('div',
            // для того чтобы имя аттрибута было в $_POST[formName][attribute] как делает Yii
            // если дальше отсутствуют input с таким же именем которые перезапишут это поле
            Html::hiddenInput($this->inputName) .

            // файлы
            $this->renderFiles() .

            // кнопка добавления
            $this->renderAddButton(),

            $this->options
        );
    }
}
