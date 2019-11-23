<?php
/**
 * @copyright 2019-2019 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 24.11.19 00:29:11
 */

declare(strict_types = 1);
namespace dicr\file;

use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use function array_slice;
use function count;
use function in_array;
use function is_array;

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
 * @property array clientOptions
 * @property \yii\web\View $view
 * @property \dicr\file\StoreFile[]|null $value файлы
 */
trait FileInputWidgetTrait
{
    /** @var string вид 'images' или 'files' */
    public $layout = 'images';

    /** @var int|null максимальное кол-во файлов */
    public $limit;

    /** @var string|null mime-типы в input type=file, например image/* */
    public $accept;

    /** @var bool|null удалять расширение файла при отображении (default true for horizontal) */
    public $removeExt;

    /** @var string|null название поля формы аттрибута */
    public $inputName;

    /**
     * {@inheritdoc}
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidConfigException
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

        if (! in_array($this->layout, ['images', 'files'])) {
            throw new InvalidConfigException('layout');
        }

        if (! isset($this->removeExt)) {
            $this->removeExt = $this->layout === 'files';
        }

        // получаем название поля ввода файлов
        if (! isset($this->inputName)) {
            $this->inputName = Html::getInputName($this->model, $this->attribute);
        }

        // получаем файлы
        if (! isset($this->value)) {
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

        // добавляем опции клиенту
        $this->clientOptions = ArrayHelper::merge([
            'layout' => $this->layout,
            'limit' => $this->limit,
            'accept' => $this->accept,
            'removeExt' => $this->removeExt,
            'inputName' => $this->inputName
        ], $this->clientOptions);
    }

    /**
     * {@inheritdoc}
     * @throws \yii\base\InvalidConfigException
     * @throws \dicr\file\StoreException
     * @see \yii\base\Widget::render()
     */
    public function run()
    {
        // регистрируем ассет
        $this->view->registerAssetBundle(FileInputWidgetAsset::class);

        // регистрируем плагин
        $this->registerPlugin('fileInputWidget');

        return Html::tag('div', // для того чтобы имя аттрибута было в $_POST[formName][attribute] как делает Yii
            // если дальше отсутствуют input с таким же именем которые перезапишут это поле
            Html::hiddenInput($this->inputName) .

            // файлы
            $this->renderFiles() .

            // кнопка добавления
            $this->renderAddButton(),

            $this->options);
    }

    /**
     * @param $name
     * @return mixed
     */
    abstract protected function registerPlugin($name);

    /**
     * Рендерит блок файлов
     *
     * @return string
     * @throws \dicr\file\StoreException
     * @throws \dicr\file\StoreException
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
     * Рендерит файл
     *
     * @param int $pos
     * @param \dicr\file\StoreFile $file
     * @return string
     * @throws \dicr\file\StoreException
     * @throws \dicr\file\StoreException
     */
    protected function renderFileBlock(int $pos, StoreFile $file)
    {
        ob_start();

        echo Html::beginTag('div', ['class' => 'file']);

        // $_POST - параметр с именем старого файла
        echo Html::hiddenInput($this->inputName . '[' . $pos . ']', $file->name);

        // картинка
        echo $this->renderImage($file);

        // имя файла
        if ($this->layout !== 'images') {
            echo Html::a($file->getName([
                'removePrefix' => 1,
                'removeExt' => $this->removeExt
            ]),

                $file->url,

                [
                    'class' => 'name',
                    'download' => $file->getName(['removePrefix' => 1])
                ]);
        }

        // кнопка удаления файла
        echo Html::button('&times;', [
            'class' => 'del btn btn-link text-danger',
            'title' => 'Удалить'
        ]);

        echo Html::endTag('div');

        return ob_get_clean();
    }

    /**
     * Рендерит картинку
     *
     * @param \dicr\file\StoreFile $file
     * @return string
     * @throws \dicr\file\StoreException
     * @throws \dicr\file\StoreException
     */
    protected function renderImage(StoreFile $file)
    {
        $img = null;

        if ($this->layout === 'images') {
            $img = Html::img(preg_match('~^image/.+~uism', $file->mimeType) ? $file->url : null, [
                'alt' => '',
                'class' => 'image'
            ]);
        } else {
            $img = Html::tag('i', '', ['class' => 'image fa fas fa-download']);
        }

        return Html::a($img, $file->url, [
            'class' => 'download',
            'download' => $file->getName(['removePrefix' => 1])
        ]);
    }

    /**
     * Рендерит кнопку добавления картинки
     *
     * @return string
     */
    protected function renderAddButton()
    {
        // id поля файла
        $fileId = $this->id . '-addinput-' . mt_rand();

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
            ]);
    }
}
