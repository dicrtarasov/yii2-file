<?php
namespace dicr\file;

/**
 * Превью файл в кэше
 *
 * @property-read int $width ширина
 * @property-read int $height высота
 * @property-read bool $watermark наличие водяного знака
 * @property-read string $format формат файла (png, jpg, ...)
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class ThumbFile extends StoreFile
{
    /** @var int */
    private $_width;

    /** @var int */
    private $_height;

    /** @var bool */
    private $_watermark;

    /** @var string */
    private $_format;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        $pathInfo = pathInfo($this->path);

        $matches = null;
        if (preg_match('~\~(\d+)x(\d+)(w)?$~uism', $pathInfo['filename'], $matches)) {
            $this->_width = (int) $matches[1];
            $this->_height = (int) $matches[2];
            $this->_watermark = ! empty($matches[3]);

            $this->_format = $pathInfo['extension'] ?? '';
            if ($this->_format == '') {
                throw new StoreException('invalid format: ' . $pathInfo['extension']);
            }
        }
    }

    /**
     * Возвращает ширину
     *
     * @return int
     */
    public function getWidth()
    {
        return $this->_width;
    }

    /**
     * Возвращает высоту
     *
     * @return int
     */
    public function getHeight()
    {
        return $this->_height;
    }

    /**
     * Возвращает флаг наличия водяного знака
     *
     * @return boolean
     */
    public function getWatermark()
    {
        return $this->_watermark;
    }

    /**
     * Возвращает формат файла
     *
     * @return string расширение (jpg, png, ...)
     */
    public function getFormat()
    {
        return $this->_format;
    }

    /**
     * Создает объект файла по оригинальному файлу и параметрам
     *
     * @param AbstractFile $origFile путь оригинального файла
     * @param AbstractFileStore $cacheStore кэш превью
     * @param int $width ширина
     * @param int $height высота
     * @param bool $watermark наличие водяного знака
     * @param string $format формат файлов (jpg, png, ...)
     * @return StoreFile путь файла в кэше
     */
    public static function forFile(AbstractFile $origFile, AbstractFileStore $cacheStore, int $width = 0, int $height = 0,
        bool $watermark = false, string $format = 'jpg')
    {
        if (empty($origFile)) {
            throw new \InvalidArgumentException('origFile');
        }

        if ($width < 0) {
            throw new \InvalidArgumentException('width');
        }

        if ($height < 0) {
            throw new \InvalidArgumentException('height');
        }

        if ($format == '') {
            throw new \InvalidArgumentException('format');
        }

        // настраиваем store на создание файлов данного класса
        $cacheStore->fileConfig['class'] = static::class;

        // путь файла в кэше
        $cacheDir = $cacheStore->file($origFile->path)->parent;
        if (empty($cacheDir)) {
            throw new \InvalidArgumentException('origFile');
        }

        // имя файла
        $newName = sprintf('%s~%dx%d%s.%s', pathInfo($origFile->name, PATHINFO_FILENAME), $width, $height,
            $watermark ? 'w' : '', $format);

        return $cacheDir->child($newName);
    }

    /**
     * Фильтр листинга файлов
     *
     * @param StoreFile $file фильтруемый файл
     * @return bool принять/отказать
     */
    public static function filterListFile(StoreFile $file)
    {
        if (empty($file)) {
            throw new \InvalidArgumentException('file');
        }

        return (bool) preg_match('~\~\d+x\d+w?\.\w+$~uism', $file->path);
    }
}