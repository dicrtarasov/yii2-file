<?php
namespace dicr\file;

use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\di\Instance;

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
     * @param \dicr\file\AbstractFileStore $store хранилище файлов
     * @param \dicr\file\AbstractFile $src исходный файл
     * @param array $config конфиг
     */
    public function __construct(array $config = [])
    {
        $store = Instance::ensure(ArrayHelper::remove($config, 'store'), AbstractFileStore::class);

        // если noimage === true, то удаляем из конфига чтобы не переписать дефолтное значение
        if (!empty($config['noimage']) && $config['noimage'] === true) {
            unset($config['noimage']);
        }

        // если watermark === true, то удаляем из конфига чтобы не перезаписать дефолтное значение
        if (!empty($config['watermark']) && $config['watermark'] === true) {
            unset($config['watermark']);
        }

        // если disclaimer === true, то удаляем из конфига чтобы не перезаписать дефолтное значение
        if (!empty($config['disclaimer']) && $config['disclaimer'] === true) {
            unset($config['disclaimer']);
        }

        parent::__construct($store, '', $config);
    }

    /**
     * {@inheritDoc}
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
                $this->noimage = \Yii::getAlias($this->noimage, true);
                if (!is_file($this->noimage) || !is_readable($this->noimage)) {
                    throw new InvalidConfigException('noimage не доступен: ' . $this->noimage);
                }
            } elseif ($this->noimage !== false) {
                throw new InvalidConfigException('noimage');
            }
        }

        if (isset($this->watermark)) {
            if (is_string($this->watermark)) {
                $this->watermark = \Yii::getAlias($this->watermark);
                if (!is_file($this->watermark) || !is_readable($this->watermark)) {
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
                $this->disclaimer = \Yii::getAlias($this->disclaimer, true);
                if (!is_file($this->disclaimer) || !is_readable($this->disclaimer)) {
                    throw new InvalidConfigException('disclaimer не доступен');
                }
            } elseif ($this->disclaimer !== false) {
                throw new InvalidConfigException('disclaimer: ' . gettype($this->disclaimer));
            }
        }

        // проверяем источник
        if (isset($this->source)) {
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
        return preg_replace('~^(.+)\.[^\.]+$~ui',
            sprintf('${1}~%dx%d%s%s.%s', $this->width, $this->height,
                !$this->isNoimage && !empty($this->watermark) ? '~w' : '',
                !$this->isNoimage && !empty($this->disclaimer) ? '~d' : '',
                preg_quote($this->format)
            ),
            $this->isNoimage ? 'noimage/' . $this->source->name : $this->source->path
        );
    }

    /**
     * Возвращает каринку.
     *
     * @throws \ImagickException
     * @return \Imagick
     */
    protected function getImage()
    {
        if (!isset($this->_image)) {
            // создаем каринку
            $this->_image = new \Imagick();

            // пытаемся прочитать исходную каринку
            try {
                $this->_image->readimageblob($this->source->contents);
            } catch (\Throwable $ex) {
                // если уже noimage или не задан noimage, то выбрасываем исключение
                if ($this->isNoimage || empty($this->noimage)) {
                    throw $ex;
                }

                // читаем каринку noimage
                $this->_image->readimage($this->noimage);
                $this->isNoimage = true;
                $this->_path = $this->createPath();
            }
        }

        return $this->_image;
    }

    /**
     * Сохраняет картинку превью.
     */
    protected function writeImage()
    {
        if (empty($this->_image)) {
            throw new \LogicException('empty image');
        }

        // очищаем лишнюю информацию
        $this->_image->stripImage();

        // формат
        if ($this->_image->setImageFormat($this->format) === false) {
            throw new \Exception('Ошибка установки формата картинки: ' . $this->format);
        }

        // сжатие
        if ($this->image->setImageCompressionQuality((int) round($this->quality * 100)) === false) {
            throw new \Exception('Ошибка установки качества изображения: ' . $this->quality);
        }

        // сохраняем
        $this->contents = $this->_image->getimageblob();
    }

    /**
     * Масштабирует картинку.
     */
    protected function resizeImage()
    {
        // если заданы размеры, то делаем масштабирование
        if ($this->width > 0 || $this->height > 0) {
            $image = $this->getImage();

            if (! $image->thumbnailimage($this->width, $this->height, $this->width && $this->height, $this->width && $this->height)) {
                throw new \Exception('error creating thumb');
            }
        }
    }

    /**
     * Накладывает водяной знак.
     */
    protected function watermarkImage()
    {
        if ($this->isNoimage || empty($this->watermark)) {
            return;
        }

        $watermark = null;

        try {
            // получаем размеры изображения
            $image = $this->getImage();
            $width = $image->getimagewidth();
            $height = $image->getimageheight();

            // создаем картинку для водяного знака
            $watermark = new \Imagick($this->watermark);
            $wwidth = $watermark->getimagewidth();
            $wheight = $watermark->getimageheight();

            // применяем opacity
            if (! empty($this->watermarkOpacity) && $this->watermarkOpacity < 1) {
                $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $this->watermarkOpacity, \Imagick::CHANNEL_ALPHA);
            }

            // масштабируем водяной знак
            if ($wwidth != $width || $wheight != $height) {
                $watermark->scaleImage($width, $height, true);
            }

            // накладываем на изображение
            $image->compositeImage(
                $watermark, \Imagick::COMPOSITE_DEFAULT,
                (int)round(($width - $wwidth) / 2),
                (int)round(($height - $wheight) / 2)
            );
        } finally {
            if (!empty($watermark)) {
                $watermark->destroy();
            }
        }
    }

    /**
     * Накладывает пометку о возрастных ограничениях.
     */
    protected function placeDisclaimer()
    {
        if ($this->isNoimage || empty($this->disclaimer)) {
            return;
        }

        $disclaimer = null;
        try {
            // получаем картинку
            $image = $this->getImage();
            $iwidth = $image->getimagewidth();
            $iheight = $image->getimageheight();

            // создаем изображение
            $disclaimer = new \Imagick($this->disclaimer);
            $dwidth = (int)round($iwidth / 10);
            $dheight = (int)round($iheight / 10);

            // изменяем размер
            $disclaimer->scaleImage($dwidth, $dheight, true);

            // добавляем прозрачность
            $disclaimer->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.65, \Imagick::CHANNEL_OPACITY);

            // выполняем наложение
            $image->compositeImage(
                $disclaimer, \Imagick::COMPOSITE_DEFAULT,
                (int)round($iwidth - $dwidth * 1.3),
                (int)round($dheight * 0.3)
            );
        } finally {
            if (!empty($disclaimer)) {
                $disclaimer->destroy();
            }
        }
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
     * После обработка перед сохранением.
     * (для дочерних классов)
     */
    protected function postprocessImage()
    {
        // NOOP
    }

    /**
     * Проверяет актуальность превью.
     *
     * @return boolean true если существует и дата изменения не раньше чем у исходного
     */
    public function getIsReady()
    {
        if (empty($this->source)) {
            throw new \LogicException('empty source');
        }

        return $this->exists && $this->mtime >= $this->source->mtime;
    }

    /**
     * Обновляет превью.
     *
     * @throws \Exception
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
     * Удаляет все превью для заданного файла.
     *
     * @param StoreFile $file оригинальный файл, все преью которого очищаются.
     * @param array $config конфиг для ThumbFile
     * @throws InvalidConfigException
     */
    public function clear()
    {
        if (empty($this->source)) {
            throw new \Exception('empty source');
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
        if (!empty($this->_image)) {
            $this->_image->destroy();
            $this->_image = null;
        }
    }
}