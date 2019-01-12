<?php
namespace dicr\file;

use yii\base\InvalidConfigException;
use yii\bootstrap\InputWidget;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * Виджет редактора картинок
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class FileInputWidget extends InputWidget
{

    /** @var int|null максимальное кол-во файлов */
    public $limit;

    /** @var string|false mime-типы в input type=file, например image/* */
    public $accept;

    /** @var \dicr\file\AbstractFileStore|string */
    public $store = 'fileStore';

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
        if (! isset($this->model)) {
            throw new InvalidConfigException('model');
        }

        if (! isset($this->attribute)) {
            throw new InvalidConfigException('attribute');
        }

        // получаем store
        if (is_string($this->store)) {
            $this->store = \Yii::$app->get($this->store);
        } elseif (is_array($this->store)) {
            $this->store = \Yii::createObject($this->store);
        }

        if (!($this->store instanceof AbstractFileStore)) {
            throw new InvalidConfigException('store');
        }

        // получаем inputName
        $this->attribute = preg_replace('~\[\]$~uism', '', $this->attribute);
        $this->inputName = Html::getInputName($this->model, $this->attribute);

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

        // добавляем опции клиенту
        $this->clientOptions = ArrayHelper::merge([
            'accept' => $this->accept,
            'limit' => $this->limit,
            'inputName' => $this->inputName
        ], $this->clientOptions);

        // добавляем нужные классы
        Html::addCssClass($this->options, 'file-input-widget');

        // добавляем enctype форме
        $this->field->form->options['enctype'] = 'multipart/form-data';

        // отключаем валидацию на стороне клиента
        $this->field->enableClientValidation = false;

        // инициируем
        parent::init();

        // регистрируем ассеты и плагин
        $this->view->registerAssetBundle(FileInputWidgetAsset::class);
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
        $isImage = preg_match('~^image\/.+~uism', $file->mimeType);
        $fileId = $this->id . '-fileinput-' . rand(1, 999999);

        return Html::tag('label',
            Html::hiddenInput($this->inputName . '[' . $pos . ']', $file->name) .
            Html::fileInput($this->inputName . '[' . $pos . ']', null, ['id' => $fileId,'accept' => $this->accept]) .
            Html::img($isImage ? $file->url : null) .
            Html::tag('div', $file->getName(['removePrefix' => 1,'removeExt' => 1]), ['class' => 'name']) .
            Html::button('&times;', ['class' => 'del btn btn-link text-danger','title' => 'удалить']),
            ['class' => 'file btn','for' => $fileId]);
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
                $content .= $this->renderFileBlock((int) $pos, $file);
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
        $fileInputId = $this->id . '-addinput-' . rand(1, 999999);
        return Html::label(
            Html::fileInput(null, null, ['accept' => $this->accept ?: null,'id' => $fileInputId]) .
            Html::tag('i', '', [
                'class' => 'fa fas fa-plus-circle text-success'
            ]), $fileInputId, [
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
            Html::hiddenInput($this->inputName) . $this->renderFiles() . $this->renderAddButton(),
            $this->options
        );
    }
}
