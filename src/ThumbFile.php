<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 24.01.22 04:56:07
 */

declare(strict_types=1);
namespace dicr\file;

use Imagick;
use ImagickException;
use ImagickPixel;
use ImagickPixelException;
use RuntimeException;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;

use function in_array;
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
 * @property-read bool $isSkipConvert файл имеет формат, которые не конвертируются
 */
class ThumbFile extends File
{
    /** пропуск resize для указанных форматов */
    public const SKIP_FORMATS = ['svg', 'pdf'];

    /** Исходный файл. Если пустой, то применяется noimage */
    public ?File $source = null;

    public int $width = 0;

    public int $height = 0;

    /**
     * дополнять до заданных размеров заполняя пустое пространство одной из величин.
     * Примеры: `rgb(255,255,255)`, `#fff`, ...
     *
     * Применяется только когда заданы оба размера (width и height).
     *
     * Картинка масштабируется всегда пропорционально. Если задан fill, то устанавливается в заданные размеры
     * с заполнением пустого пространства цветом fill.
     *
     * Если true, то принимается значение '#fff'
     */
    public string|bool|null $fill = null;

    /** путь картинки-заглушки или функция, которая возвращает путь */
    public ?string $noimage = '@dicr/file/assets/noimage.png';

    /** путь картинки водяного знака (полупрозрачный png) */
    public ?string $watermark = null;

    /** путь картинки дисклеймера */
    public ?string $disclaimer = null;

    /** качество сжатия картинки */
    public float $quality = 0.95;

    /** формат файла */
    public string $format = 'jpg';

    /** файл является заглушкой noimage */
    protected bool $isNoimage = false;

    /** сырое изображение */
    protected ?Imagick $_image = null;

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
        /** @var FileStore $store */
        $store = Instance::ensure($config['store'] ?? '', FileStore::class);

        unset($config['store']);

        parent::__construct($store, '', $config);
    }

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if ($this->width < 0) {
            throw new InvalidConfigException('width');
        }

        if ($this->height < 0) {
            throw new InvalidConfigException('height');
        }

        // fill конвертируем true в '#fff'
        if ($this->fill === true) {
            $this->fill = '#fff';
        }

        // noimage
        if (!empty($this->noimage)) {
            $this->noimage = (string)Yii::getAlias($this->noimage);
            if (!is_file($this->noimage)) {
                throw new InvalidConfigException('noimage недоступен: ' . $this->noimage);
            }
        }

        if (!empty($this->watermark)) {
            $this->watermark = (string)Yii::getAlias($this->watermark);
            if (!is_file($this->watermark)) {
                throw new InvalidConfigException('watermark недоступен: ' . $this->watermark);
            }
        }

        if (!empty($this->disclaimer)) {
            $this->disclaimer = Yii::getAlias($this->disclaimer);
            if (!is_string($this->disclaimer) || !is_file($this->disclaimer)) {
                throw new InvalidConfigException('disclaimer недоступен');
            }
        }

        if ($this->quality < 0 || $this->quality > 1) {
            throw new InvalidConfigException('quality: ' . $this->quality);
        }

        // проверяем источник
        if ((empty($this->source) || !$this->source->exists) && !empty($this->noimage)) {
            // если не указан источник, то считаем это noimage
            $this->source = LocalFileStore::root()->file($this->noimage);
            $this->isNoimage = true;
        } elseif ($this->isSkipConvert) {
            $this->_store = $this->source->store;
        }

        // обновляем путь картинки в кеше
        $this->_path = $this->createPath();
    }

    /**
     * Пропуск конвертирования.
     */
    public function getIsSkipConvert(): bool
    {
        return !empty($this->source) &&
            in_array((string)$this->source->extension, self::SKIP_FORMATS);
    }

    /**
     * Генерирует путь картинки в кеше.
     */
    protected function createPath(): string
    {
        // если не задан источник (отсутствует), то путь липовый
        if (empty($this->source)) {
            return 'dummy-empty';
        }

        // если пропускаем конвертирование формата, то путь берем оригинального источника
        if ($this->isSkipConvert) {
            return $this->source->path;
        }

        // путь файла в кэше
        $path = $this->isNoimage ? 'noimage/' . $this->noimage : $this->source->path;

        // удаляем расширение
        $path = self::removeExtension($path);

        // добавляем размер
        $path .= '~' . $this->width . 'x' . $this->height;

        if (!$this->isNoimage) {
            // помечаем картинки с watermark
            if (!empty($this->watermark)) {
                $path .= '~w' . substr(md5($this->watermark), 0, 4);
            }

            // помечаем картинки с дисклеймером
            if (!empty($this->disclaimer)) {
                $path .= '~d' . substr(md5($this->watermark), 0, 4);
            }

            if ($this->width > 0 && $this->height > 0 && !empty($this->fill)) {
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
    public function getIsReady(): bool
    {
        // файл не задан - делать нечего
        if (empty($this->source)) {
            return true;
        }

        // если файл не конвертируемый, то готов
        if ($this->isSkipConvert) {
            return true;
        }

        return $this->mtime >= $this->source->mtime;
    }

    /**
     * Обновляет превью.
     *
     * @throws StoreException
     */
    public function update(): static
    {
        if ($this->isSkipConvert) {
            return $this;
        }

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
     * @throws StoreException
     */
    public function generate(): static
    {
        if (!$this->isReady) {
            $this->update();
        }

        return $this;
    }

    /**
     * @inheritDoc
     * @throws StoreException
     */
    public function getUrl(): ?string
    {
        // обновляем файл превью
        $this->generate();

        return !empty($this->source) ? parent::getUrl() : null;
    }

    /**
     * Возвращает картинку.
     *
     * @throws StoreException
     */
    protected function image(): Imagick
    {
        if (!isset($this->_image)) {
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
     */
    protected function preprocessImage(): static
    {
        // NOOP
        return $this;
    }

    /**
     * Масштабирует картинку.
     *
     * @throws StoreException
     * @link https://urmaul.com/blog/imagick-filters-comparison/ FILTERS
     */
    protected function resizeImage(): static
    {
        // если заданы размеры, то делаем масштабирование
        if (!empty($this->width) || !empty($this->height)) {
            try {
                $image = $this->image();
                $image->setOption('filter:support', '2.0');
                $image->setColorspace(Imagick::COLORSPACE_SRGB);
                $image->setImageBackgroundColor(new ImagickPixel($this->fill ?: '#fff'));
                $image->setImageInterlaceScheme(Imagick::INTERLACE_JPEG);

                // масштабировать вписывая в заданную область
                $bestFit = $this->width > 0 && $this->height > 0;

                // дополняем цветом заполнения до нужных размеров
                $fill = $bestFit && !empty($this->fill);

                // очищает профили и заполняет пустое пространство
                if (!$image->thumbnailImage($this->width, $this->height, $bestFit, $fill)) {
                    Yii::error('Ошибка создания thumbnail', __METHOD__);
                }
            } catch (ImagickException|ImagickPixelException $ex) {
                throw new StoreException('Ошибка изменения размера', $ex);
            }
        }

        return $this;
    }

    /**
     * Накладывает водяной знак.
     *
     * @throws StoreException
     */
    protected function watermarkImage(): static
    {
        if ($this->isNoimage || empty($this->watermark)) {
            return $this;
        }

        $watermark = null;

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
            /** @noinspection PhpConditionAlreadyCheckedInspection */
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
     * @throws StoreException
     */
    protected function placeDisclaimer(): static
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
            /** @noinspection PhpConditionAlreadyCheckedInspection */
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
     */
    protected function postprocessImage(): static
    {
        // NOOP
        return $this;
    }

    /**
     * Сохраняет картинку превью.
     *
     * @throws StoreException
     */
    protected function writeImage(): static
    {
        $image = $this->image();

        try {
            // формат
            if ($image->setImageFormat($this->format) === false) {
                throw new StoreException('Ошибка установки формата картинки: ' . $this->format);
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
        } catch (ImagickException $ex) {
            throw new StoreException('Ошибка сохранения картинки', $ex);
        }

        return $this;
    }

    /**
     * Регулярное выражения для названий превью файла.
     *
     * @param string $name название файла
     * @return string регулярное выражение для поиска превью
     */
    private static function createNameRegex(string $name): string
    {
        return '~^' .
            preg_quote(self::removeExtension($name), '~') .
            '\~\d+x\d+(\~w[0-9a-f]{4})?(\~d[0-9a-f]{4})?(\~f)?\.[^\.]+$~ui';
    }

    /**
     * Удаляет превью заданного файла.
     *
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function clear(): static
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
     */
    public function __toString(): string
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
