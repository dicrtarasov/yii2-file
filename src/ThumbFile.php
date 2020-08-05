<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 05.08.20 23:19:03
 */

/** @noinspection SpellCheckingInspection */

declare(strict_types = 1);

namespace dicr\file;

use Imagick;
use ImagickException;
use RuntimeException;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use function gettype;
use function is_file;
use function is_readable;
use function is_string;
use function mb_strtolower;
use function md5;
use function preg_replace;
use function round;
use function substr;

/**
 * Превью файл в кэше.
 *
 * Для своих функций disclaimer и watermark можно наследовать класс и переопределить функции, дальше в конфиге store
 * указать свой класс в конфиге thumbFile.
 *
 * @property-read bool $isReady флаг сущесвования готового превью
 */
class ThumbFile extends StoreFile
{
    /**
     * @var StoreFile|null исходный файл
     * Если пустой, то применяется noimage
     */
    public $source;

    /** @var int */
    public $width = 0;

    /** @var int */
    public $height = 0;

    /** @var string|false путь картинки-заглушки или функция, которая возвращает путь */
    public $noimage = '@dicr/file/assets/noimage.png';

    /** @var string путь картинки водяного знака */
    public $watermark = '';

    /** @var float прозрачность картинки watermark */
    public $watermarkOpacity = 0.7;

    /** @var string путь каринки дисклеймера */
    public $disclaimer = '';

    /** @var float качество сжаия каринки */
    public $quality = 0.8;

    /** @var string формат файла */
    public $format = 'jpg';

    /** @var bool файл является заглушкой noimage */
    protected $isNoimage = false;

    /** @var Imagick сырое изображение */
    protected $_image;

    /**
     * Конструктор.
     *
     * @param array $config конфиг
     * Чтобы не применялись по-умолчанию, watermark и disclaimer сбрасываются в пустые значения.
     * Чтобы применить watermark, disclaimer по-умолчанию моно установить значения в true.
     *
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function __construct(array $config = [])
    {
        $store = Instance::ensure(ArrayHelper::remove($config, 'store'), AbstractFileStore::class);

        /** @noinspection PhpParamsInspection */
        parent::__construct($store, '', $config);
    }

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init() : void
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

        if (! empty($this->noimage)) {
            if (is_string($this->noimage)) {
                $this->noimage = Yii::getAlias($this->noimage);
                if (! is_file($this->noimage) || ! is_readable($this->noimage)) {
                    throw new InvalidConfigException('noimage недоступен: ' . $this->noimage);
                }
            } else {
                throw new InvalidConfigException('noimage: ' . gettype($this->noimage));
            }
        }

        if (! empty($this->watermark)) {
            if (is_string($this->watermark)) {
                $this->watermark = Yii::getAlias($this->watermark);
                if (! is_file($this->watermark) || ! is_readable($this->watermark)) {
                    throw new InvalidConfigException('watermark недоступен: ' . $this->watermark);
                }
            } else {
                throw new InvalidConfigException('watermark: ' . gettype($this->watermark));
            }
        }

        $this->watermarkOpacity = (float)$this->watermarkOpacity;
        if ($this->watermarkOpacity < 0 || $this->watermarkOpacity > 1) {
            throw new InvalidConfigException('watermarkOpacity: ' . $this->watermarkOpacity);
        }

        if (! empty($this->disclaimer)) {
            if (is_string($this->disclaimer)) {
                $this->disclaimer = Yii::getAlias($this->disclaimer);
                if (! is_file($this->disclaimer) || ! is_readable($this->disclaimer)) {
                    throw new InvalidConfigException('disclaimer недоступен');
                }
            } else {
                throw new InvalidConfigException('disclaimer: ' . gettype($this->disclaimer));
            }
        }

        $this->quality = (float)$this->quality;
        if ($this->quality < 0 || $this->quality > 1) {
            throw new InvalidConfigException('quality: ' . $this->quality);
        }

        // проверяем источник
        if (! empty($this->source)) {
            if (! ($this->source instanceof StoreFile)) {
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
     * Генерирует путь картинки в кеше.
     *
     * @return string
     */
    protected function createPath() : string
    {
        // путь файла в кэше
        $path = $this->isNoimage ? 'noimage/' . $this->noimage : $this->source->path;

        // удаляем расшиение
        $path = preg_replace('~\.[^\.]+$~u', '', $path);

        // добавляем размер
        $path .= '~' . $this->width . 'x' . $this->height;

        if (! $this->isNoimage) {
            // помечаем картинки с watermark
            if (! empty($this->watermark)) {
                $path .= '~w' . substr(md5($this->watermark), 0, 4);
            }

            // помечаем картинки с дисклеймером
            if (! empty($this->disclaimer)) {
                $path .= '~d' . substr(md5($this->watermark), 0, 4);
            }
        }

        // добавляем расширение
        return $path . '.' . mb_strtolower($this->format);
    }

    /**
     * Проверяет актуальность превью.
     *
     * @return bool true если существует и дата изменения не раньше чем у исходного
     */
    public function getIsReady() : bool
    {
        return $this->exists && $this->mtime >= $this->source->mtime;
    }

    /**
     * Обновляет превью.
     *
     * @throws StoreException
     */
    public function update() : self
    {
        $this->preprocessImage();
        $this->resizeImage();
        $this->watermarkImage();
        $this->placeDisclaimer();
        $this->postprocessImage();
        $this->writeImage();

        return $this;
    }

    /**
     * Предварительная обработка картинки после загрузки.
     * (для дочерних классов)
     */
    protected function preprocessImage() : self
    {
        // NOOP
        return $this;
    }

    /**
     * Масштабирует картинку.
     *
     * @throws StoreException
     */
    protected function resizeImage() : self
    {
        // если заданы размеры, то делаем масштабирование
        if (! empty($this->width) || ! empty($this->height)) {
            $image = $this->image();
            if (! $image->thumbnailImage($this->width, $this->height, $this->width && $this->height,
                $this->width && $this->height)) {
                throw new RuntimeException('error creating thumb');
            }
        }

        return $this;
    }

    /**
     * Возвращает каринку.
     *
     * @return Imagick
     * @throws StoreException
     */
    protected function image() : Imagick
    {
        if (! isset($this->_image)) {
            // создаем каринку
            $this->_image = new Imagick();

            // пытаемся прочитать исходную каринку
            try {
                $this->_image->readImageBlob($this->source->contents);
            } catch (ImagickException $ex) {
                // если уже noimage или не задан noimage, то выбрасываем исключение
                if ($this->isNoimage || empty($this->noimage)) {
                    throw new StoreException('Ошибка создания превью', $ex);
                }

                // читаем каринку noimage
                try {
                    $this->_image->readImage($this->noimage);
                } catch (ImagickException $ex) {
                    throw new StoreException('Ошибка чтения картинки: ' . $this->noimage, $ex);
                }

                // помечаем картинку как noimage и пересчитываем путь
                $this->isNoimage = true;
                $this->_path = $this->createPath();
            }
        }

        return $this->_image;
    }

    /**
     * Накладывает водяной знак.
     *
     * @throws StoreException
     */
    protected function watermarkImage() : self
    {
        if ($this->isNoimage || empty($this->watermark)) {
            return $this;
        }

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
        } catch (ImagickException $ex) {
            throw new StoreException('Ошибка создания watermark: ' . $this->watermark, $ex);
        } finally {
            if (! empty($watermark)) {
                $watermark->destroy();
            }
        }

        return $this;
    }

    /**
     * Накладывает пометку о возрастных ограничениях.
     *
     * @throws StoreException
     */
    protected function placeDisclaimer() : self
    {
        if ($this->isNoimage || empty($this->disclaimer)) {
            return $this;
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
        } catch (ImagickException $ex) {
            throw new StoreException('Ошибка наложения disclaimer: ' . $this->disclaimer, $ex);
        } finally {
            if ($disclaimer !== null) {
                $disclaimer->destroy();
            }
        }

        return $this;
    }

    /**
     * После обработка перед сохранением.
     * (для дочерних классов)
     */
    protected function postprocessImage() : self
    {
        // NOOP
        return $this;
    }

    /**
     * Сохраняет картинку превью.
     *
     * @throws StoreException
     */
    protected function writeImage() : self
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
        return $this;
    }

    /**
     * Удаляет все превью для заданного файла.
     *
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function clear() : self
    {
        if (empty($this->source)) {
            throw new RuntimeException('empty source');
        }

        $dir = $this->store->file($this->source->path)->parent;
        if ($dir !== null) {
            $files = $dir->getList([
                'nameRegex' => '~^.+\~\d+x\d+(\~[wd0-9a-f])*\.[^\.]+$~',
                'dir' => false
            ]);

            foreach ($files as $file) {
                $file->delete();
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function __toString() : string
    {
        return (string)$this->url;
    }

    /**
     * Деструктор.
     */
    public function __destruct()
    {
        if (! empty($this->_image)) {
            $this->_image->destroy();
            unset($this->_image);
        }
    }
}
