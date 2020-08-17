<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 17.08.20 22:15:14
 */

declare(strict_types = 1);
namespace dicr\file;

use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\widgets\InputWidget;

use function array_slice;
use function count;
use function gettype;
use function in_array;
use function is_array;
use function ksort;
use function mt_rand;
use function ob_get_clean;
use function ob_start;
use function preg_match;

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
 * Чтобы не было привязки к версии bootstrap, виджет наследует базовый yii\widgets\InputWidget
 *
 * @property StoreFile[]|null $value файлы
 */
class FileInputWidget extends InputWidget
{
    /** @var string дизайн для загрузки картинок */
    public const LAYOUT_IMAGES = 'images';

    /** @var string дизайн для загрузки файлов */
    public const LAYOUT_FILES = 'files';

    /** @var string вид 'images' или 'files' */
    public $layout = self::LAYOUT_IMAGES;

    /** @var int|null максимальное кол-во файлов */
    public $limit;

    /** @var string|null mime-типы в input type=file, например image/* */
    public $accept;

    /** @var bool|null удалять расширение файла при отображении (default true for horizontal) */
    public $removeExt;

    /** @var string|null название поля формы аттрибута */
    public $inputName;

    /** @var array опции плагина */
    public $clientOptions = [];

    /** @var string уникальный идентификатор */
    private $uniqueClass;

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();

        // layout
        if (! in_array($this->layout, [self::LAYOUT_IMAGES, self::LAYOUT_FILES], true)) {
            throw new InvalidConfigException('layout: ' . $this->layout);
        }

        // limit
        $this->limit = (int)$this->limit;
        if (! empty($this->limit) && $this->limit < 0) {
            throw new InvalidConfigException('limit: ' . $this->limit);
        }

        // removeExt
        if (! isset($this->removeExt)) {
            $this->removeExt = $this->layout === 'files';
        }

        // получаем название поля ввода файлов
        if (! isset($this->inputName)) {
            $this->inputName = $this->hasModel() ? Html::getInputName($this->model, $this->attribute) : $this->name;
        }

        // корректируем поле ввода, убирая "[]" в конце
        $matches = null;
        if (preg_match('~^(.+)\[\]$~u', $this->inputName, $matches)) {
            $this->inputName = $matches[1];
        }

        // получаем файлы
        if (! isset($this->value)) {
            $this->value = $this->hasModel() ? Html::getAttributeValue($this->model, $this->attribute) : [];
        }

        if (empty($this->value)) {
            $this->value = [];
        } elseif (! is_array($this->value)) {
            $this->value = [$this->value]; // нельзя применять (array) потому как File::toArray
        }

        // проверяем все значения на StoreFile
        foreach ($this->value as $file) {
            if (! ($file instanceof StoreFile)) {
                throw new InvalidConfigException('value file: ' . gettype($file));
            }
        }

        // сортируем значения
        ksort($this->value);

        // ограничиваем лимитов
        if ($this->limit > 0) {
            $this->value = array_slice($this->value, 0, $this->limit, true);
        }

        // добавляем enctype форме
        $this->field->form->options['enctype'] = 'multipart/form-data';

        // отключаем проверку на стороне клиента
        $this->field->enableClientValidation = false;

        // добавляем рабочие классы
        Html::addCssClass($this->options, ['file-input-widget']);
        Html::addCssClass($this->options, 'layout-' . $this->layout);

        // добавляем класс с уникальным идентификатором, так как id занято служебными скриптами activeForm.js
        $this->uniqueClass = 'widget-file-input-' . mt_rand();
        Html::addCssClass($this->options, $this->uniqueClass);

        // добавляем опции клиенту
        $this->clientOptions = ArrayHelper::merge([
            'layout' => $this->layout,
            'limit' => $this->limit,
            'accept' => $this->accept,
            'removeExt' => $this->removeExt,
            'inputName' => $this->inputName,
            'messages' => [
                'Удалить' => Yii::t('dicr/file', 'Удалить')
            ]
        ], $this->clientOptions);
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function run()
    {
        // регистрируем ресурсы
        $this->view->registerAssetBundle(FileInputWidgetAsset::class);

        // регистрируем плагин
        $this->view->registerJs(
            '$(".' . $this->uniqueClass . '").fileInputWidget(' . Json::encode($this->clientOptions) . ');'
        );

        return Html::tag('section', // для того чтобы имя аттрибута было в $_POST[formName][attribute] как делает Yii
            // если дальше отсутствуют input с таким же именем которые перезапишут это поле
            Html::hiddenInput($this->inputName) .

            // файлы
            $this->renderFiles() .

            // кнопка добавления
            $this->renderAddButton(),

            $this->options
        );
    }

    /**
     * Верстает блок файлов
     *
     * @return string
     */
    protected function renderFiles(): string
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
     * Верстает блок файла
     *
     * @param int $pos
     * @param StoreFile $file
     * @return string
     */
    protected function renderFileBlock(int $pos, StoreFile $file): string
    {
        ob_start();
        echo Html::beginTag('div', ['class' => 'file']);

        // $_POST - параметр с именем старого файла
        echo Html::hiddenInput($this->inputName . '[' . $pos . ']', $file->name);

        // картинка
        echo $this->renderImage($file);

        // имя файла
        if ($this->layout !== self::LAYOUT_IMAGES) {
            echo Html::a($file->getName([
                'removePrefix' => 1,
                'removeExt' => $this->removeExt
            ]), $file->url, [
                'class' => 'name',
                'download' => $file->getName(['removePrefix' => 1])
            ]);
        }

        // кнопка удаления файла
        echo Html::button('&times;', [
            'class' => 'del btn btn-link text-danger',
            'title' => Yii::t('dicr/file', 'Удалить')
        ]);

        echo Html::endTag('div');

        return ob_get_clean();
    }

    /**
     * Верстает блок картинки
     *
     * @param StoreFile $file
     * @return string
     */
    protected function renderImage(StoreFile $file): string
    {
        $img = $this->layout === self::LAYOUT_IMAGES ?
            Html::img(preg_match('~^image/.+~uism', $file->mimeType) ? $file->url : null, [
                'alt' => '',
                'class' => 'image'
            ]) : Html::tag('i', '', [
                'class' => 'image fa fas fa-download'
            ]);

        return Html::a($img, $file->url, [
            'class' => 'download',
            'download' => $file->getName(['removePrefix' => 1])
        ]);
    }

    /**
     * Верстает кнопку добавления картинки
     *
     * @return string
     */
    protected function renderAddButton(): string
    {
        // id поля файла
        $fileId = $this->id . '-addinput-' . mt_rand();

        return Html::label(
        // $_FILES параметр файла
            Html::fileInput(null, null, [
                'accept' => $this->accept ?: null,
                'id' => $fileId
            ]) .

            // знак кнопки
            Html::tag('i', '', [
                'class' => 'fa fas fa-plus-circle text-success'
            ]),

            $fileId,

            [
                'class' => 'add',
                'title' => Yii::t('dicr/file', 'Добавить файл'),
                'style' => [
                    'display' => $this->limit > 0 && count($this->value) >= $this->limit ? 'none' : 'flex'
                ]
            ]);
    }
}
