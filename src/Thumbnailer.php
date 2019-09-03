<?php
namespace dicr\file;

use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Создает превью файлов.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class Thumbnailer extends Component
{
    /** @var string|array|AbstractFileStore хранилище файлов кэша */
    public $cacheStore;

    /** @var string путь картинки по-умолчанию когда нет возможности создать превью */
    public $noimage = '@dicr/file/res/noimage.png';

    /** @var string путь картинки для наложения водяного знака */
    public $watermark = '';

    /** @var float watermark opacity 0.01 .. 0.99 */
    public $watermarkOpacity = 0.07;

    /** @var string thumbnail image format */
    public $format = 'jpg';

    /** @var float image compression level 0.01 .. 0.99 */
    public $quality = 0.85;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        if (is_string($this->cacheStore)) {
            $this->cacheStore = \Yii::$app->get($this->cacheStore, true);
        } elseif (is_array($this->cacheStore)) {
            if (! isset($this->cacheStore['class'])) {
                $this->cacheStore['class'] = LocalFileStore::class;
            }
            $this->cacheStore = \Yii::createObject($this->cacheStore);
        }

        if (! ($this->cacheStore instanceof AbstractFileStore)) {
            throw new InvalidConfigException('cacheStore');
        }

        $this->cacheStore->fileConfig['class'] = ThumbFile::class;

        $this->noimage = trim($this->noimage);
        if ($this->noimage != '') {
            $this->noimage = \Yii::getAlias($this->noimage, true);
            if ($this->noimage === false) {
                throw new InvalidConfigException('noimage');
            }
        }

        $this->watermark = trim($this->watermark);
        if ($this->watermark != '') {
            $this->watermark = \Yii::getAlias($this->watermark, true);
            if ($this->watermark === false) {
                throw new InvalidConfigException('watermark');
            }
        }

        $this->watermarkOpacity = (float) $this->watermarkOpacity;
        if ($this->watermarkOpacity < 0 || $this->watermarkOpacity > 1) {
            throw new InvalidConfigException('watermarkOpacity');
        }

        $this->format = trim($this->format);
        if ($this->format == '') {
            throw new InvalidConfigException('format');
        }

        $this->quality = (float) $this->quality;
        if ($this->quality < 0 || $this->quality > 1) {
            throw new InvalidConfigException('quality must be 0.01 .. 0.99');
        }
    }

    /**
     * Возвращает файл кэша, соответствующий оригинальному файлу с заданными параметрами
     *
     * @param AbstractFile $origFile относительный путь файла
     * @param int $width ширина превью
     * @param int $height высота превью
     * @param bool $watermark наличие водяного знака
     * @return \dicr\file\ThumbFile кэш файл
     */
    public function cacheFile(AbstractFile $origFile, int $width, int $height, bool $watermark)
    {
        return ThumbFile::forFile($origFile, $this->cacheStore, $width, $height, $watermark, $this->format);
    }

    /**
     * Возвращает список всех имеющихся в кэше превью файла с заданным путем
     *
     * @param AbstractFile $origFile относительный путь оригинального файла
     * @throws StoreException
     * @return \dicr\file\StoreFile[] список существующих в кэше превью
     */
    public function listFiles(AbstractFile $origFile)
    {
        if (empty($origFile)) {
            throw new \InvalidArgumentException('origFile');
        }

        $cacheDir = $this->cacheStore->file($origFile)->parent;
        if (empty($cacheDir)) {
            throw new \InvalidArgumentException('origFile');
        }

        $list = $cacheDir->getList(['dir' => false,'filter' => [ThumbFile::class,'filterListFile']]);

        usort($list, function ($a, $b) {
            return strnatcasecmp($a->path, $b->path);
        });

        return $list;
    }

    /**
     * Удаляет их кэша все превью файла с заданным путем.
     *
     * @param AbstractFile $origFile оригинальный файл
     * @throws StoreException
     * @return static
     */
    public function deleteFiles(AbstractFile $origFile)
    {
        foreach ($this->listFiles($origFile) as $cacheFile) {
            $cacheFile->delete();
        }

        return $this;
    }

    /**
     * Create image from file
     *
     * @param AbstractFile $origFile file or path
     * @throws StoreException
     * @return \Imagick
     */
    protected function readImage(AbstractFile $origFile)
    {
        // читаем картинку
        $image = new \Imagick();
        $stream = $origFile->stream;

        try {
            if (! $image->readimagefile($stream)) {
                throw new StoreException('error reading image');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $image;
    }

    /**
     * Resize image
     *
     * @param \Imagick $image
     * @param int $width
     * @param int $height
     * @return static
     */
    protected function resizeImage(\Imagick $image, int $width, int $height)
    {
        if (empty($image)) {
            throw new \InvalidArgumentException('image');
        }

        if ($width < 0) {
            throw new \InvalidArgumentException('width');
        }

        if ($height < 0) {
            throw new \InvalidArgumentException('height');
        }

        // если заданы размеры, то делаем масштабирование
        if ($width > 0 || $height > 0) {

            if (! $image->thumbnailImage($width, $height, $width && $height, $width && $height)) {
                throw new Exception('error creating thumb');
            }
        }

        return $this;
    }

    /**
     * Накладывает водяной знак
     *
     * @param \Imagick $image исходная каринка
     * @param string $watermark путь файла водяного знака
     * @throws StoreException
     * @return static
     */
    protected function watermarkImage(\Imagick $image, string $watermark)
    {
        if (empty($image)) {
            throw new \InvalidArgumentException('image');
        }

        if (empty($watermark)) {
            return;
        }

        $watermark = new \Imagick();
        if (! $watermark->readimage($watermark)) {
            throw new StoreException('error reading watermark: ' . $watermark);
        }

        if (! empty($this->watermarkOpacity)) {
            $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.07, \Imagick::CHANNEL_ALPHA);
        }

        $watermark->scaleImage($image->getImageWidth(), $image->getImageHeight(), true);
        $image->compositeImage($watermark, \Imagick::COMPOSITE_DEFAULT,
            ($image->getImageWidth() - $watermark->getImageWidth()) / 2,
            ($image->getImageHeight() - $watermark->getImageHeight()) / 2);

        $watermark->destroy();

        return $this;
    }

    /**
     * Сохраняет изображение
     *
     * @param \Imagick $image
     * @param string $thumbFile
     * @throws Exception
     * @return static
     */
    protected function writeImage(\Imagick $image, ThumbFile $thumbFile)
    {
        if (empty($image)) {
            throw new \InvalidArgumentException('image');
        }

        if (empty($thumbFile)) {
            throw new \InvalidArgumentException('thumbFile');
        }

        // очищаем лишнюю информацию
        $image->stripImage();

        // формат
        if ($image->setImageFormat($this->format) === false) {
            throw new Exception('error setting image format: ' . $this->format);
        }

        // сжатие
        if ($image->setImageCompressionQuality((int) round($this->quality * 100)) === false) {
            throw new Exception('error setting image compression quality');
        }

        // сохраняем
        $thumbFile->contents = $image->getimageblob();

        return $this;
    }

    /**
     * Create image thumbnail
     *
     * @param AbstractFile $origFile
     * @param array $options
     * @throws \Throwable
     * @return ThumbFile
     */
    protected function thumbnailFile(AbstractFile $origFile, array $options)
    {
        if (empty($origFile)) {
            throw new \InvalidArgumentException('origFile');
        }

        $width = (int) ($options['width'] ?? 0);
        if ($width < 0) {
            throw new \InvalidArgumentException('width');
        }

        $height = (int) ($options['height'] ?? 0);
        if ($height < 0) {
            throw new \InvalidArgumentException('height');
        }

        $watermark = $options['watermark'] ?? true;
        if (is_bool($watermark)) {
            $watermark = $watermark ? $this->watermark : '';
        }

        $thumbFile = $this->cacheFile($origFile, $width, $height, $watermark);

        if (! $thumbFile->exists || $thumbFile->mtime < $origFile->mtime) {
            try {
                $image = $this->readImage($origFile);
                $this->resizeImage($image, $width, $height);
                $this->watermarkImage($image, $watermark);
                $this->writeImage($image, $thumbFile);
            } finally {
                if (! empty($image)) {
                    $image->destroy();
                }
            }
        }

        return $thumbFile;
    }

    /**
     * Создает превью файла
     *
     * @param AbstractFile $origFile оригинальный файл
     * @param array $options опции
     *        - int|null $with
     *        - int|null $height
     *        - bool|string $watermark водяной знак. Если true или не задан, то используется по-умолчанию
     *        - bool|string $noimage заглушка при ошике создания. Если true или не задан, то используется по-умлчанию.
     * @throws \InvalidArgumentException
     * @throws StoreException
     * @return \dicr\file\ThumbFile
     */
    public function process(AbstractFile $origFile, array $options = [])
    {
        if (empty($origFile)) {
            throw new \InvalidArgumentException('origFile');
        }

        // noimage
        $noimage = $options['noimage'] ?? true;
        if (is_bool($noimage)) {
            $noimage = $noimage ? $this->noimage : '';
        }

        // пытаемся создать оригинальный превью
        try {
            $thumbFile = $this->thumbnailFile($origFile, $options);
        } catch (\Throwable $ex) {
            \Yii::warning('Ошибка создания превью файла: ' . $origFile->path . ': ' . $ex->getMessage(), __METHOD__);

            if (! empty($noimage)) {

                // пытаемся создать превью из заглушки
                try {
                    $noimageFile = LocalFileStore::root()->file($noimage);
                    $options['watermark'] = false;
                    $thumbFile = $this->thumbnailFile($noimageFile, $options);
                } catch (\Throwable $ex) {
                    throw new StoreException('ошибка создания превью: ' . $origFile->path, $ex);
                }
            } else {
                throw new StoreException('ошибка создания превью: ' . $origFile->path, $ex);
            }
        }

        return $thumbFile;
    }
}
