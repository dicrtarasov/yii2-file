<?php
namespace dicr\file;

use yii\base\Component;
use yii\base\Exception;
use yii\di\Instance;
use yii\helpers\ArrayHelper;

/**
 * Создает превью файлов.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 *
 *
 * $file = $file;
 * $tmb = $file->thumb(
 */
class Thumbnailer extends Component
{
    /** @var \dicr\file\LocalFileStore|array|string хранилище файлов кэша */
    public $cacheStore;

    /** @var string|null путь картинки-заглушки по-умолчанию */
    public $noimage = '@dicr/file/res/noimage.png';

    /** @var array конфиг файла превью по-умолчанию */
    public $thumbFileConfig = [];

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        $this->cacheStore = Instance::ensure($this->cacheStore, LocalFileStore::class);

        // noimage
        if (!empty($this->noimage)) {
            $this->noimage = \Yii::getAlias($this->noimage, true);
        }

        // thumbFileConfig
        if (!empty($this->thumbFileConfig['watermark'])) {
            $this->thumbFileConfig['watermark'] = \Yii::getAlias($this->thumbFileConfig['watermark'], true);
        }
    }

    /**
     * Читает картинку.
     *
     * @param \dicr\file\ThumbFile $thumbFile
     * @throws \dicr\file\StoreException
     * @return \Imagick
     */
    protected function readImage(ThumbFile $thumbFile)
    {
        // читаем картинку
        $image = new \Imagick();
        $stream = $thumbFile->src->stream;

        try {
            if (! $image->readimagefile($stream)) {
                throw new StoreException('Ошибка чтения картинки: ' . $thumbFile->src->path);
            }
        } finally {
            if (!empty($stream)) {
                /** @scrutinizer ignore-unhandled */
                @fclose($stream);
            }
        }

        return $image;
    }

    /**
     * Масштабирует картинку.
     *
     * @param \dicr\file\ThumbFile $thumbFile
     * @param \Imagick $image
     * @return $this
     */
    protected function resizeImage(ThumbFile $file, \Imagick $image)
    {
        if (empty($image)) {
            throw new \InvalidArgumentException('image');
        }

        // если заданы размеры, то делаем масштабирование
        if ($file->width > 0 || $file->height > 0) {
            if (! $image->thumbnailimage($file->width, $file->height, $file->width && $file->height, $file->width && $file->height)) {
                throw new Exception('error creating thumb');
            }
        }

        return $this;
    }

    /**
     * Накладывает водяной знак.
     *
     * @param \dicr\file\ThumbFile $thumbFile
     * @param \Imagick $image исходная каринка
     * @throws \dicr\file\StoreException
     * @return $this
     */
    protected function watermarkImage(ThumbFile $thumbFile, \Imagick $image)
    {
        if (empty($thumbFile->watermark)) {
            return $this;
        }

        try {
            // создаем картинку для водяного знака
            $watermark = new \Imagick();
            if (! $watermark->readimage($thumbFile->watermark)) {
                throw new StoreException('Ошибка чтения маски: ' . $thumbFile->watermark);
            }

            // применяем opacity
            if (! empty($thumbFile->watermarkOpacity) && $thumbFile->watermarkOpacity < 1) {
                $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.07, \Imagick::CHANNEL_ALPHA);
            }

            // получаем размеры изображения
            $width = $image->getimagewidth();
            $height = $image->getimageheight();

            // масштабируем водяной знак
            if ($watermark->getimagewidth() != $width || $watermark->getimageheight() != $height) {
                $watermark->scaleImage($width, $height, true);
            }

            // накладываем на изображение
            $image->compositeImage($watermark, \Imagick::COMPOSITE_DEFAULT,
                ($width - $watermark->getImageWidth()) / 2,
                ($height - $watermark->getImageHeight()) / 2);
        } finally {
            if (!empty($watermark)) {
                // освобождаем каринку водяного знака
                $watermark->destroy();
            }
        }

        return $this;
    }

    /**
     * Сохраняет изображение.
     *
     * @param \dicr\file\ThumbFile $thumbFile
     * @param \Imagick $image
     * @throws \dicr\file\StoreException
     * @return $this
     */
    protected function writeImage(ThumbFile $thumbFile, \Imagick $image)
    {
        // очищаем лишнюю информацию
        $image->stripImage();

        // формат
        if ($image->setImageFormat($thumbFile->format) === false) {
            throw new Exception('Ошибка установки формата картинки: ' . $thumbFile->format);
        }

        // сжатие
        if ($image->setImageCompressionQuality((int) round($thumbFile->quality * 100)) === false) {
            throw new Exception('Ошибка установки качества изображения: ' . $thumbFile->quality);
        }

        // сохраняем
        $thumbFile->contents = $image->getimageblob();

        return $this;
    }

    /**
     * Создает превью из файла.
     *
     * @param \dicr\file\ThumbFile $src
     * @return \dicr\file\ThumbFile
     */
    protected function processFile(ThumbFile $thumbFile)
    {
        // если файл уже существует и свежий, то возвращаем его без изменений
        if ($thumbFile->isValid()) {
            return $thumbFile;
        }

        try {
            $image = $this->readImage($thumbFile);
            $this->resizeImage($thumbFile, $image);
            $this->watermarkImage($thumbFile, $image);
            $this->writeImage($thumbFile, $image);
        } finally {
            if (! empty($image)) {
                $image->destroy();
            }
        }

        return $thumbFile;
    }

    /**
     * Создает превью файла
     *
     * @param \dicr\file\AbstractFile $src оригинальный файл
     *
     * @param array $config конфиг \dicr\file\ThumbFile
     *  - int|null $with
     *  - int|null $height
     *
     *  - bool|string|null $watermark водяной знак.
     *  Если true или не задан, то используется значение по-умолчанию.
     *
     *  - bool|string $noimage заглушка при ошике создания.
     *  Если true или не задан, то используется значение по-умлчанию.
     *
     * @throws \InvalidArgumentException
     * @throws \dicr\file\StoreException
     *
     * @return \dicr\file\ThumbFile|null файл превью или null если исходный файл не существует
     */
    public function process(AbstractFile $src, array $config = [])
    {
        // проверяем аргументы
        if (empty($src)) {
            throw new \InvalidArgumentException('origFile');
        }

        // если $confg['watermark'] == ture, то удаляем, точбы не перезаписывал
        if (isset($config['watermark']) && $config['watermark'] === true) {
            unset($config['watermark']);
        }

        // дополняем конфиг значениями по-умолчанию
        $config = array_merge($this->thumbFileConfig, $config);

        // выделяем параметр noimage
        $noimage = ArrayHelper::remove($config, 'noimage');
        if ($noimage === true) {
            $noimage = $this->noimage;
        }

        // если файл сущесвует
        if ($src->exists) {
            $thumbFile = new ThumbFile($this->cacheStore, $src, array_merge($config, [
                'noimage' => false
            ]));

            try {
                return $this->processFile($thumbFile);
            } catch (\Throwable $ex) {
                \Yii::warning($ex, __METHOD__);
            }
        }

        // возращаем noimage нужного размера
        if (! empty($noimage)) {
            $srcNoimage = LocalFileStore::root()->file($noimage);

            $thumbFile = new ThumbFile($this->cacheStore, $srcNoimage, array_merge($config, [
                'noimage' => true,
                'watermark' => null
            ]));

            return $this->processFile($thumbFile);
        }

        return null;
    }

    /**
     * Удаляет их кэша превью файла.
     *
     * @param AbstractFile $src относительный путь оригинального файла
     * @throws \dicr\file\StoreException
     */
    public function clear(AbstractFile $src)
    {
        $dir = $this->cacheStore->file($src->path)->parent;
        if (empty($dir)) {
            throw new StoreException('Некорректный файл для очистки');
        }

        $files = $dir->getList([
            'nameRegex' => ThumbFile::PATHNAME_REGEX,
            'dir' => false
        ]);

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
