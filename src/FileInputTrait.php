<?php
namespace dicr\file;

use yii\base\InvalidConfigException;
use yii\bootstrap\Html;
use yii\helpers\ArrayHelper;

/**
 * Виджет ввода картинок.
 *
 * Использовать в проекте для создания класса FileInputWidget от нужного пакета Bootstrap:
 *
 * class FileInputWidget extends \yii\bootstrap(4?)\InputWidget
 * {
 *   use FileInputTrait;
 * }
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
trait FileInputTrait
{
    /** @var int|null максимальное кол-во файлов */
    public $limit;

    /** @var string|false mime-типы в input type=file, например image/* */
    public $accept;

    /** @var string вид 'horizontal' или 'vertical' */
    public $layout = 'horizontal';

    /** @var string название поля формы аттрибута */
    protected $inputName;

    /** @var \dicr\file\AbstractFile[] файлы */
    protected $files;

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

        // получаем файлы
        $this->files = Html::getAttributeValue($this->model, $this->attribute);
        if (empty($this->files)) {
            $this->files = [];
        } elseif (! is_array($this->files)) {
            $this->files = [$this->files]; // нельзя применять (array) потому как File::toArray
        } elseif ($this->limit > 0) {
            ksort($this->files);
            $this->files = array_slice($this->files, 0, $this->limit, true);
        }

        // получаем название поля ввода для виджета, удаляя конечные '[]' для последующего добавления позиции файла
        $this->inputName = Html::getInputName($this->model, $this->attribute);

        // добавляем нужные классы
        Html::addCssClass($this->options, 'file-input-widget');
        Html::addCssClass($this->options, 'layout-' . $this->layout);

        // добавляем enctype форме
        $this->field->form->options['enctype'] = 'multipart/form-data';

        // отключаем валидацию на стороне клиента
        $this->field->enableClientValidation = false;

        // регистрируем ассет
        $this->view->registerAssetBundle(FileInputAsset::class);

        // добавляем опции клиенту
        $this->clientOptions = ArrayHelper::merge([
            'accept' => $this->accept,
            'limit' => $this->limit,
            'inputName' => $this->inputName
        ], $this->clientOptions);

        // регистрируем плагин
        $this->registerPlugin('fileInputWidget');
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
        // id поля файла
        $fileId = $this->id . '-fileinput-' . rand(1, 999999);

        return Html::label(
            // $_POST - параметр с именем старого файла
            Html::hiddenInput($this->inputName . '[' . $pos . ']', $file->name) .

            // $_FILE параметр для изменения файла
            Html::fileInput($this->inputName . '[' . $pos . ']', null, [
                'id' => $fileId,
                'accept' => $this->accept
            ]) .

            // картинка
            Html::img(preg_match('~^image\/.+~uism', $file->mimeType) ? $file->url : null, [
                'alt' => "",
                'class' => 'img'
            ]) .

            /*
            Html::tag('object', '', [
                'data' => preg_match('~^image\/.+~uism', $file->mimeType) ? $file->url : null,
                'class' => 'img'
            ]) .*/

            // имя файла
            Html::tag('div', $file->getName([
                'removePrefix' => 1,
                'removeExt' => 1
            ]), [
                'class' => 'name'
            ]) .

            // кнопка удаления файла
            Html::button('&times;', [
                'class' => 'del btn btn-link text-danger',
                'title' => 'удалить'
            ]),

            $fileId,

            [
                'class' => 'file btn'
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

        foreach ($this->files as $pos => $file) {
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
                'class' => 'add btn',
                'title' => 'Выбрать файл',
                'style' => [
                    'display' => $this->limit > 0 && count($this->files) >= $this->limit ? 'none' : 'flex'
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
