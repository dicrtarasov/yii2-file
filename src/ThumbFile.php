<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 17.12.20 15:51:36
 */

declare(strict_types = 1);
namespace dicr\file;

use Imagick;
use ImagickException;
use ImagickPixel;
use RuntimeException;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;

use function gettype;
use function is_file;
use function is_string;
use function mb_strtolower;
use function md5;
use function preg_quote;
use function round;
use function substr;

/**
 * Превью файл в кэше.
 *
 * Для своих функций disclaimer и watermark можно наследовать класс и переопределить функции, дальше в конфиге store
 * указать свой класс в конфиге thumbFile.
 *
 * Файл превью создается/обновляется при вызове getUrl. Если же необходимо получить файл превью без обращения к его url,
 * можно вызвать $thumbFile->generate()
 *
 * @property-read bool $isReady флаг существования готового превью
 */
class ThumbFile extends StoreFile
{
    /**
     * @var ?StoreFile исходный файл
     * Если пустой, то применяется noimage
     */
    public $source;

    /** @var int */
    public $width = 0;

    /** @var int */
    public $height = 0;

    /**
     * @var ?string дополнять до заданных размеров заполняя пустое пространство одной из величин.
     * Примеры: `rgb(255,255,255)`, `#fff`, ...
     *
     * Применяется только когда заданы оба размера (width и height).
     *
     * Картинка масштабируется всегда пропорционально. Если задан fill, то устанавливается в заданные размеры
     * с заполнением пустого пространства цветом fill.
     *
     * Если true, то принимается значение '#fff'
     */
    public $fill;

    /** @var ?string путь картинки-заглушки или функция, которая возвращает путь */
    public $noimage = '@dicr/file/assets/noimage.png';

    /** @var string путь картинки водяного знака (полупрозрачный png) */
    public $watermark = '';

    /** @var string путь картинки дисклеймера */
    public $disclaimer = '';

    /** @var float качество сжатия картинки */
    public $quality = 0.95;

    /** @var string формат файла */
    public $format = 'jpg';

    /** @var bool файл является заглушкой noimage */
    protected $isNoimage = false;

    /** @var ?Imagick сырое изображение */
    protected $_image;

    /**
     * Конструктор.
     *
     * @param array $config конфиг
     * - обязательно значение store
     * Чтобы не применялись по-умолчанию, watermark и disclaimer сбрасываются в пустые значения.
     * Чтобы применить watermark, disclaimer по-умолчанию моно установить значения в true.
     *
     * @throws InvalidConfigException
     */
    public function __construct(array $config = [])
    {
        /** @var AbstractFileStore $store */
        $store = Instance::ensure($config['store'] ?? '', AbstractFileStore::class);

        unset($config['store']);

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

        // fill конвертируем true в '#fff'
        if ($this->fill === true) {
            $this->fill = '#fff';
        }

        // noimage
        if (! empty($this->noimage)) {
            if (is_string($this->noimage)) {
                $this->noimage = (string)Yii::getAlias($this->noimage);
                if (! is_file($this->noimage)) {
                    throw new InvalidConfigException('noimage недоступен: ' . $this->noimage);
                }
            } else {
                throw new InvalidConfigException('noimage: ' . gettype($this->noimage));
            }
        }

        if (! empty($this->watermark)) {
            if (is_string($this->watermark)) {
                $this->watermark = (string)Yii::getAlias($this->watermark);
                if (! is_file($this->watermark)) {
                    throw new InvalidConfigException('watermark недоступен: ' . $this->watermark);
                }
            } else {
                throw new InvalidConfigException('watermark: ' . gettype($this->watermark));
            }
        }

        if (! empty($this->disclaimer)) {
            if (is_string($this->disclaimer)) {
                $this->disclaimer = Yii::getAlias($this->disclaimer);
                if (! is_string($this->disclaimer) || ! is_file($this->disclaimer)) {
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

        // удаляем расширение
        $path = self::removeExtension($path);

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

            if ($this->width > 0 && $this->height > 0 && ! empty($this->fill)) {
                $path .= '~f';
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
     * @return $this
     * @throws StoreException
     */
    public function update() : self
    {
        /** @noinspection PhpExpressionResultUnusedInspection */
        $this->preprocessImage();
        $this->resizeImage();
        $this->watermarkImage();
        $this->placeDisclaimer();
        /** @noinspection PhpExpressionResultUnusedInspection */
        $this->postprocessImage();
        $this->writeImage();

        // сбрасываем кэш файловой системы
        $this->_store->clearStatCache($this->path);

        return $this;
    }

    /**
     * Генерирует превью.
     *
     * @return $this
     * @throws StoreException
     */
    public function generate() : self
    {
        if (! $this->isReady) {
            $this->update();
        }

        return $this;
    }

    /**
     * @inheritDoc
     * @throws StoreException
     */
    public function getUrl() : ?string
    {
        // обновляем файл превью
        $this->generate();

        return parent::getUrl();
    }

    /**
     * Возвращает картинку.
     *
     * @return Imagick
     * @throws StoreException
     */
    protected function image() : Imagick
    {
        if (! isset($this->_image)) {
            // создаем картинку
            $this->_image = new Imagick();

            // пытаемся прочитать исходную картинку
            try {
                $this->_image->readImageBlob($this->source->contents);
                $this->_image = $this->_image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            } catch (ImagickException $ex) {
                // если уже noimage или не задан noimage, то выбрасываем исключение
                if ($this->isNoimage || empty($this->noimage)) {
                    throw new StoreException('Ошибка создания превью', $ex);
                }

                // читаем картинку noimage
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
     * Предварительная обработка картинки после загрузки.
     * (для дочерних классов)
     *
     * @return $this
     */
    protected function preprocessImage() : self
    {
        // NOOP
        return $this;
    }

    /**
     * Масштабирует картинку.
     *
     * @return $this
     * @throws StoreException
     *
     * @link https://urmaul.com/blog/imagick-filters-comparison/ FILTERS
     */
    protected function resizeImage() : self
    {
        // если заданы размеры, то делаем масштабирование
        if (! empty($this->width) || ! empty($this->height)) {
            $image = $this->image();
            $image->setOption('filter:support', '2.0');
            $image->setColorspace(Imagick::COLORSPACE_SRGB);
            $image->setImageBackgroundColor(new ImagickPixel($this->fill ?: '#fff'));
            $image->setImageInterlaceScheme(Imagick::INTERLACE_JPEG);

            // масштабировать вписывая в заданную область
            $bestFit = $this->width > 0 && $this->height > 0;

            // дополняем цветом заполнения до нужных размеров
            $fill = $bestFit && ! empty($this->fill);

            // очищает профили и заполняет пустое пространство
            if (! $image->thumbnailImage($this->width, $this->height, $bestFit, $fill)) {
                Yii::error('Ошибка создания thumbnail', __METHOD__);
            }
        }

        return $this;
    }

    /**
     * Накладывает водяной знак.
     *
     * @return $this
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
            $iWidth = $image->getImageWidth();
            $iHeight = $image->getImageHeight();

            // создаем картинку для водяного знака
            $watermark = new Imagick($this->watermark);
            $wWidth = $watermark->getImageWidth();
            $wHeight = $watermark->getImageHeight();

            // масштабируем водяной знак
            if ($wWidth !== $iWidth || $wHeight !== $iHeight) {
                $watermark->scaleImage($iWidth, $iHeight, true);
                $wWidth = $watermark->getImageWidth();
                $wHeight = $watermark->getImageHeight();
            }

            // накладываем на изображение
            $image->compositeImage(
                $watermark, Imagick::COMPOSITE_DEFAULT,
                (int)round(($iWidth - $wWidth) / 2), (int)round(($iHeight - $wHeight) / 2)
            );
        } catch (ImagickException $ex) {
            throw new StoreException('Ошибка создания watermark: ' . $this->watermark, $ex);
        } finally {
            if ($watermark !== null) {
                $watermark->clear();
                $watermark->destroy();
            }
        }

        return $this;
    }

    /**
     * Накладывает пометку о возрастных ограничениях.
     *
     * @return $this
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
            $iWidth = $image->getImageWidth();
            $iHeight = $image->getImageHeight();

            // создаем изображение
            $disclaimer = new Imagick($this->disclaimer);

            // изменяем размер
            $disclaimer->scaleImage((int)round($iWidth / 10), (int)round($iHeight / 10), true);
            $dWidth = $disclaimer->getImageWidth();
            $dHeight = $disclaimer->getImageHeight();

            // добавляем прозрачность
            $disclaimer->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.7, Imagick::CHANNEL_OPACITY);

            // выполняем наложение
            $image->compositeImage(
                $disclaimer, Imagick::COMPOSITE_DEFAULT,
                (int)round($iWidth - $dWidth * 1.3), (int)round($dHeight * 0.3)
            );
        } catch (ImagickException $ex) {
            throw new StoreException('Ошибка наложения disclaimer: ' . $this->disclaimer, $ex);
        } finally {
            if ($disclaimer !== null) {
                $disclaimer->clear();
                $disclaimer->destroy();
            }
        }

        return $this;
    }

    /**
     * После обработка перед сохранением.
     * (для дочерних классов)
     *
     * @return $this
     */
    protected function postprocessImage() : self
    {
        // NOOP
        return $this;
    }

    /**
     * Сохраняет картинку превью.
     *
     * @return $this
     * @throws StoreException
     */
    protected function writeImage() : self
    {
        $image = $this->image();

        // формат
        if ($image->setImageFormat($this->format) === false) {
            throw new RuntimeException('Ошибка установки формата картинки: ' . $this->format);
        }

        $image->setImageCompression(Imagick::COMPRESSION_JPEG);

        // сжатие
        if ($image->setImageCompressionQuality((int)round($this->quality * 100)) === false) {
            throw new RuntimeException('Ошибка установки качества изображения: ' . $this->quality);
        }

        // очищаем лишнюю информацию
        $image->stripImage();

        // сохраняем
        $this->contents = $image->getImageBlob();

        return $this;
    }

    /**
     * Регулярное выражения для названий превью файла.
     *
     * @param string $name название файла
     * @return string регулярное выражение для поиска превью
     */
    private static function createNameRegex(string $name) : string
    {
        return '~^' .
            preg_quote(self::removeExtension($name), '~') .
            '\~\d+x\d+(\~w[0-9a-f]{4})?(\~d[0-9a-f]{4})?(\~f)?\.[^\.]+$~ui';
    }

    /**
     * Удаляет превью заданного файла.
     *
     * @return $this
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function clear() : self
    {
        if (empty($this->source)) {
            throw new RuntimeException('empty source');
        }

        // путь файла в кэше
        $file = $this->store->file($this->isNoimage ? 'noimage/' . $this->noimage : $this->source->path);

        // директория файла в кеше
        $dir = $file->parent;
        if ($dir !== null && $dir->exists) {
            // находим файлы по маске
            $files = $dir->getList([
                'nameRegex' => self::createNameRegex($file->name),
                'dir' => false
            ]);

            foreach ($files as $thumbFile) {
                $thumbFile->delete();
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     * @return string
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
        if ($this->_image !== null) {
            $this->_image->destroy();
            $this->_image = null;
        }
    }
}
