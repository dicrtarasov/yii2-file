<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 */

/** @noinspection LongInheritanceChainInspection */
/** @noinspection SpellCheckingInspection */

declare(strict_types = 1);
namespace dicr\file;

use Imagick;
use LogicException;
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
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class ThumbFile extends StoreFile
{
    /** @var \dicr\file\AbstractFile исходный файл */
    public $source;

    /** @var int */
    public $width;

    /** @var int */
    public $height;

    /** @var string путь картинки-заглушки или функция, которая возвращает путь */
    public $noimage = __DIR__ . '/res/noimage.png';

    /** @var string callable путь картинки водяного знака */
    public $watermark = false;

    /** @var float прозрачность картинки watermark */
    public $watermarkOpacity = 0.7;

    /** @var string|false путь каринки дисклеймера */
    public $disclaimer;

    /** @var float качество сжаия каринки */
    public $quality = 0.8;

    /** @var string формат файла */
    public $format = 'jpg';

    /** @var bool файл является заглушкой noimage */
    public $isNoimage = false;

    /** @var \Imagick сырое изображение */
    protected $_image;

    /**
     * Конструктор.
     *
     * @param array $config конфиг
     * @throws \yii\base\InvalidConfigException
     */
    public function __construct(array $config = [])
    {
        $store = Instance::ensure(ArrayHelper::remove($config, 'store'), AbstractFileStore::class);

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
     * @throws \yii\base\InvalidConfigException
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        if (isset($this->width)) {
            $this->width = (int)$this->width;
            if ($this->width < 0) {
                throw new InvalidConfigException('width');
            }
        }

        if (isset($this->height)) {
            $this->height = (int)$this->height;
            if ($this->height < 0) {
                throw new InvalidConfigException('height');
            }
        }

        if (isset($this->noimage)) {
            if (is_string($this->noimage)) {
                $this->noimage = Yii::getAlias($this->noimage, true);
                if (! is_file($this->noimage) || ! is_readable($this->noimage)) {
                    throw new InvalidConfigException('noimage не доступен: ' . $this->noimage);
                }
            } elseif ($this->noimage !== false) {
                throw new InvalidConfigException('noimage');
            }
        }

        if (isset($this->watermark)) {
            if (is_string($this->watermark)) {
                $this->watermark = Yii::getAlias($this->watermark);
                if (! is_file($this->watermark) || ! is_readable($this->watermark)) {
                    throw new InvalidConfigException('watermark не доступен: ' . $this->watermark);
                }
            } elseif ($this->watermark !== false) {
                throw new InvalidConfigException('watermark');
            }
        }

        if (isset($this->watermarkOpacity)) {
            $this->watermarkOpacity = (float)$this->watermarkOpacity;
            if ($this->watermarkOpacity < 0 || $this->watermarkOpacity > 1) {
                throw new InvalidConfigException('watermarkOpacity: ' . $this->watermarkOpacity);
            }
        }

        if (isset($this->quality)) {
            $this->quality = (float)$this->quality;
            if ($this->quality < 0 || $this->quality > 1) {
                throw new InvalidConfigException('quality: ' . $this->quality);
            }
        }

        if (isset($this->disclaimer)) {
            if (is_string($this->disclaimer)) {
                $this->disclaimer = Yii::getAlias($this->disclaimer, true);
                if (! is_file($this->disclaimer) || ! is_readable($this->disclaimer)) {
                    throw new InvalidConfigException('disclaimer не доступен');
                }
            } elseif ($this->disclaimer !== false) {
                throw new InvalidConfigException('disclaimer: ' . gettype($this->disclaimer));
            }
        }

        // проверяем источник
        if (isset($this->source)) {
            if (! ($this->source instanceof AbstractFile)) {
                throw new InvalidConfigException('source: ' . gettype($this->source));
            }
        } elseif (! empty($this->noimage)) {
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
            ! $this->isNoimage && ! empty($this->watermark) ? '~w' : '',
            ! $this->isNoimage && ! empty($this->disclaimer) ? '~d' : '', preg_quote($this->format, '~')),
            $this->isNoimage ? 'noimage/' . $this->source->name : $this->source->path);
    }

    /**
     * Проверяет актуальность превью.
     *
     * @return boolean true если существует и дата изменения не раньше чем у исходного
     */
    public function getIsReady()
    {
        if (empty($this->source)) {
            throw new LogicException('empty source');
        }

        return $this->exists && $this->mtime >= $this->source->mtime;
    }

    /**
     * Обновляет превью.
     *
     * @throws \Exception
     * @throws \Throwable
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
     * @throws \ImagickException
     * @throws \Throwable
     */
    protected function resizeImage()
    {
        // если заданы размеры, то делаем масштабирование
        if ($this->width > 0 || $this->height > 0) {
            $image = $this->getImage();

            if (! $image->thumbnailImage($this->width, $this->height, $this->width && $this->height,
                $this->width && $this->height)) {
                throw new RuntimeException('error creating thumb');
            }
        }
    }

    /**
     * Возвращает каринку.
     *
     * @return \Imagick
     * @throws \ImagickException
     * @throws \Throwable
     */
    protected function getImage()
    {
        if (! isset($this->_image)) {
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
     * @throws \ImagickException
     * @throws \Throwable
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
            $image = $this->getImage();
            $iwidth = (int)$image->getImageWidth();
            $iheight = (int)$image->getImageHeight();

            // создаем картинку для водяного знака
            $watermark = new Imagick($this->watermark);
            $wwidth = (int)$watermark->getImageWidth();
            $wheight = (int)$watermark->getImageHeight();

            // применяем opacity
            if (! empty($this->watermarkOpacity) && $this->watermarkOpacity < 1) {
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
     * @throws \ImagickException
     * @throws \Throwable
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
            $image = $this->getImage();
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
     */
    protected function writeImage()
    {
        if (empty($this->_image)) {
            throw new LogicException('empty image');
        }

        // очищаем лишнюю информацию
        $this->_image->stripImage();

        // формат
        if ($this->_image->setImageFormat($this->format) === false) {
            throw new RuntimeException('Ошибка установки формата картинки: ' . $this->format);
        }

        // сжатие
        if ($this->_image->setImageCompressionQuality((int)round($this->quality * 100)) === false) {
            throw new RuntimeException('Ошибка установки качества изображения: ' . $this->quality);
        }

        // сохраняем
        $this->contents = $this->_image->getImageBlob();
    }

    /**
     * Удаляет все превью для заданного файла.
     *
     * @throws \dicr\file\StoreException
     * @throws \yii\base\InvalidConfigException
     */
    public function clear()
    {
        if (empty($this->source)) {
            throw new RuntimeException('empty source');
        }

        $dir = $this->store->file($this->source->path)->parent;

        $files = $dir->getList([
            'nameRegex' => '~^.+\~\d+x\d+(\~[wd])*\.[^\.]+$~',
            'dir' => false
        ]);

        foreach ($files as $file) {
            $file->delete();
        }
    }

    /**
     * Деструктор.
     */
    public function __destruct()
    {
        if (! empty($this->_image)) {
            $this->_image->destroy();
            $this->_image = null;
        }
    }
}
