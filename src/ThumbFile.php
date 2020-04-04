<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.04.20 20:52:09
 */

/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

namespace dicr\file;

use Exception;
use Imagick;
use ImagickException;
use RuntimeException;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use function gettype;
use function is_string;

/**
 * Превью файл в кэше.
 *
 * @property-read bool $isReady флаг сущесвования готового превью
 * @noinspection LongInheritanceChainInspection
 */
class ThumbFile extends StoreFile
{
    /** @var AbstractFile исходный файл */
    public $source;

    /** @var int */
    public $width = 0;

    /** @var int */
    public $height = 0;

    /** @var string|false путь картинки-заглушки или функция, которая возвращает путь */
    public $noimage = '@dicr/file/assets/noimage.png';

    /** @var string|false callable путь картинки водяного знака */
    public $watermark = false;

    /** @var float прозрачность картинки watermark */
    public $watermarkOpacity = 0.7;

    /** @var string|false путь каринки дисклеймера */
    public $disclaimer = false;

    /** @var float качество сжаия каринки */
    public $quality = 0.8;

    /** @var string формат файла */
    public $format = 'jpg';

    /** @var bool файл является заглушкой noimage */
    public $isNoimage = false;

    /** @var Imagick сырое изображение */
    protected $_image;

    /**
     * Конструктор.
     *
     * @param array $config конфиг
     * @throws InvalidConfigException
     */
    public function __construct(array $config = [])
    {
        $store = Instance::ensure(ArrayHelper::remove($config, 'store'), AbstractFileStore::class);

        // устанавливаем параметры по-умолчанию
        $config = array_merge([
            'noimage' => false,
            'watermark' => false,
            'disclaimer' => false
        ], $config);

        // удаляем значения true, чтобы не перезаписывали дефолтные значения
        foreach (['noimage', 'watermark', 'disclaimer'] as $field) {
            if (isset($config[$field]) && $config[$field] === true) {
                unset($config[$field]);
            }
        }

        /** @noinspection PhpParamsInspection */
        parent::__construct($store, '', $config);
    }

    /**
     * {@inheritDoc}
     * @throws InvalidConfigException
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        $this->width = (int)$this->width;
        if ($this->width < 0) {
            throw new InvalidConfigException('width');
        }

        $this->height = (int)$this->height;
        if ($this->height < 0) {
            throw new InvalidConfigException('height');
        }

        if (is_string($this->noimage)) {
            $this->noimage = Yii::getAlias($this->noimage);
            if (!is_file($this->noimage) || !is_readable($this->noimage)) {
                throw new InvalidConfigException('noimage недоступен: ' . $this->noimage);
            }
        } elseif ($this->noimage !== false) {
            throw new InvalidConfigException('noimage');
        }

        if (is_string($this->watermark)) {
            $this->watermark = Yii::getAlias($this->watermark);
            if (!is_file($this->watermark) || !is_readable($this->watermark)) {
                throw new InvalidConfigException('watermark недоступен: ' . $this->watermark);
            }
        } elseif ($this->watermark !== false) {
            throw new InvalidConfigException('watermark');
        }

        $this->watermarkOpacity = (float)$this->watermarkOpacity;
        if ($this->watermarkOpacity < 0 || $this->watermarkOpacity > 1) {
            throw new InvalidConfigException('watermarkOpacity: ' . $this->watermarkOpacity);
        }

        $this->quality = (float)$this->quality;
        if ($this->quality < 0 || $this->quality > 1) {
            throw new InvalidConfigException('quality: ' . $this->quality);
        }

        if (is_string($this->disclaimer)) {
            $this->disclaimer = Yii::getAlias($this->disclaimer);
            if (!is_file($this->disclaimer) || !is_readable($this->disclaimer)) {
                throw new InvalidConfigException('disclaimer недоступен');
            }
        } elseif ($this->disclaimer !== false) {
            throw new InvalidConfigException('disclaimer: ' . gettype($this->disclaimer));
        }

        // проверяем источник
        if (!empty($this->source)) {
            if (!($this->source instanceof AbstractFile)) {
                throw new InvalidConfigException('source: ' . gettype($this->source));
            }
        } elseif (!empty($this->noimage)) {
            // если не указан источник, то считаем это noimage
            $this->source = LocalFileStore::root()->file($this->noimage);
            $this->isNoimage = true;
        } else {
            throw new InvalidConfigException('не указан source и noimage');
        }

        // обновляем путь картинки в кеше
        $this->_path = $this->createPath();
    }

    /**
     * Обновляет путь картинки в кеше.
     *
     * @return string
     */
    protected function createPath()
    {
        return preg_replace('~^(.+)\.[^.]+$~u', sprintf('${1}~%dx%d%s%s.%s', $this->width, $this->height,
            !$this->isNoimage && !empty($this->watermark) ? '~w' : '',
            !$this->isNoimage && !empty($this->disclaimer) ? '~d' : '', preg_quote($this->format, '~')),
            $this->isNoimage ? 'noimage/' . $this->source->name : $this->source->path);
    }

    /**
     * Проверяет актуальность превью.
     *
     * @return bool true если существует и дата изменения не раньше чем у исходного
     * @noinspection PhpUnused
     */
    public function getIsReady()
    {
        return $this->exists && $this->mtime >= $this->source->mtime;
    }

    /**
     * Обновляет превью.
     *
     * @throws Exception
     * @throws Throwable
     */
    public function update()
    {
        $this->preprocessImage();
        $this->resizeImage();
        $this->watermarkImage();
        $this->placeDisclaimer();
        $this->postprocessImage();
        $this->writeImage();
    }

    /**
     * Предварительная обработка картинки после загрузки.
     * (для дочерних классов)
     */
    protected function preprocessImage()
    {
        // NOOP
    }

    /**
     * Масштабирует картинку.
     *
     * @throws ImagickException
     * @throws Throwable
     */
    protected function resizeImage()
    {
        // если заданы размеры, то делаем масштабирование
        if (!empty($this->width) || !empty($this->height)) {
            $image = $this->image();
            if (!$image->thumbnailImage($this->width, $this->height, $this->width && $this->height,
                $this->width && $this->height)) {
                throw new RuntimeException('error creating thumb');
            }
        }
    }

    /**
     * Возвращает каринку.
     *
     * @return Imagick
     * @throws ImagickException
     * @throws Throwable
     */
    protected function image()
    {
        if (!isset($this->_image)) {
            // создаем каринку
            $this->_image = new Imagick();

            // пытаемся прочитать исходную каринку
            try {
                $this->_image->readImageBlob($this->source->contents);
            } catch (Throwable $ex) {
                // если уже noimage или не задан noimage, то выбрасываем исключение
                if ($this->isNoimage || empty($this->noimage)) {
                    throw $ex;
                }

                // читаем каринку noimage
                $this->_image->readImage($this->noimage);
                $this->isNoimage = true;
                $this->_path = $this->createPath();
            }
        }

        return $this->_image;
    }

    /**
     * Накладывает водяной знак.
     *
     * @throws ImagickException
     * @throws Throwable
     */
    protected function watermarkImage()
    {
        if ($this->isNoimage || empty($this->watermark)) {
            return;
        }

        $watermark = null;

        /** @noinspection BadExceptionsProcessingInspection */
        try {
            // получаем размеры изображения
            $image = $this->image();
            $iwidth = (int)$image->getImageWidth();
            $iheight = (int)$image->getImageHeight();

            // создаем картинку для водяного знака
            $watermark = new Imagick($this->watermark);
            $wwidth = (int)$watermark->getImageWidth();
            $wheight = (int)$watermark->getImageHeight();

            // применяем opacity
            if (!empty($this->watermarkOpacity) && $this->watermarkOpacity < 1) {
                $watermark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $this->watermarkOpacity, Imagick::CHANNEL_ALPHA);
            }

            // масштабируем водяной знак
            if ($wwidth !== $iwidth || $wheight !== $iheight) {
                $watermark->scaleImage($iwidth, $iheight, true);
                $wwidth = $watermark->getImageWidth();
                $wheight = $watermark->getImageHeight();
            }

            // накладываем на изображение
            $image->compositeImage($watermark, Imagick::COMPOSITE_DEFAULT, (int)round(($iwidth - $wwidth) / 2),
                (int)round(($iheight - $wheight) / 2));
        } finally {
            if ($watermark !== null) {
                $watermark->destroy();
            }
        }
    }

    /**
     * Накладывает пометку о возрастных ограничениях.
     *
     * @throws ImagickException
     * @throws Throwable
     */
    protected function placeDisclaimer()
    {
        if ($this->isNoimage || empty($this->disclaimer)) {
            return;
        }

        $disclaimer = null;
        /** @noinspection BadExceptionsProcessingInspection */
        try {
            // получаем картинку
            $image = $this->image();
            $iwidth = $image->getImageWidth();
            $iheight = $image->getImageHeight();

            // создаем изображение
            $disclaimer = new Imagick($this->disclaimer);

            // изменяем размер
            $disclaimer->scaleImage((int)round($iwidth / 10), (int)round($iheight / 10), true);
            $dwidth = $disclaimer->getImageWidth();
            $dheight = $disclaimer->getImageHeight();

            // добавляем прозрачность
            $disclaimer->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.65, Imagick::CHANNEL_OPACITY);

            // выполняем наложение
            $image->compositeImage($disclaimer, Imagick::COMPOSITE_DEFAULT, (int)round($iwidth - $dwidth * 1.3),
                (int)round($dheight * 0.3));
        } finally {
            if ($disclaimer !== null) {
                $disclaimer->destroy();
            }
        }
    }

    /**
     * После обработка перед сохранением.
     * (для дочерних классов)
     */
    protected function postprocessImage()
    {
        // NOOP
    }

    /**
     * Сохраняет картинку превью.
     *
     * @throws ImagickException
     * @throws Throwable
     */
    protected function writeImage()
    {
        $image = $this->image();

        // очищаем лишнюю информацию
        $image->stripImage();

        // формат
        if ($image->setImageFormat($this->format) === false) {
            throw new RuntimeException('Ошибка установки формата картинки: ' . $this->format);
        }

        // сжатие
        if ($image->setImageCompressionQuality((int)round($this->quality * 100)) === false) {
            throw new RuntimeException('Ошибка установки качества изображения: ' . $this->quality);
        }

        // сохраняем
        $this->contents = $image->getImageBlob();
    }

    /**
     * Удаляет все превью для заданного файла.
     *
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function clear()
    {
        if (empty($this->source)) {
            throw new RuntimeException('empty source');
        }

        $dir = $this->store->file($this->source->path)->parent;
        if ($dir !== null) {
            $files = $dir->getList([
                'nameRegex' => '~^.+\~\d+x\d+(\~[wd])*\.[^\.]+$~',
                'dir' => false
            ]);

            foreach ($files as $file) {
                $file->delete();
            }
        }
    }

    /**
     * Деструктор.
     */
    public function __destruct()
    {
        if (!empty($this->_image)) {
            $this->_image->destroy();
            $this->_image = null;
        }
    }

    /**
     * @inheritDoc
     * @return string url
     */
    public function __toString()
    {
        return (string)$this->url;
    }
}
