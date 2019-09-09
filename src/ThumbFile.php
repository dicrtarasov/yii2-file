<?php
namespace dicr\file;

use yii\base\InvalidConfigException;

/**
 * Превью файл в кэше.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class ThumbFile extends StoreFile
{
    /** @var string регулярное выражение имени файла */
    const PATHNAME_REGEX = '~^(.+?)\~(\d+)x(\d+)(?:\~(w))?\.([^\.]+)$~ui';

    /** @var \dicr\file\AbstractFile исходный файл */
    public $src;

    /** @var int */
    public $width;

    /** @var int */
    public $height;

    /** @var bool является ли исходный файл noimage */
    public $noimage = false;

    /** @var string|null путь картинки водяного знака */
    public $watermark;

    /** @var float прозрачность картинки watermark */
    public $watermarkOpacity = 0.7;

    /** @var string формат файла */
    public $format = 'jpg';

    /** @var float качество сжаия каринки */
    public $quality = 0.85;

    /**
     * Конструктор.
     *
     * @param \dicr\file\AbstractFileStore $store хранилище файлов
     * @param \dicr\file\AbstractFile $src исходный файл
     * @param array $config конфиг
     */
    public function __construct(AbstractFileStore $store, AbstractFile $src, array $config = [])
    {
        // сохраняем исходный файл
        $this->src = $src;

        // вызываем родительский конструктор c пустым путем
        parent::__construct($store, '', $config);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        // width
        $this->width = (int)$this->width;
        if ($this->width < 0) {
            throw new InvalidConfigException('width');
        }

        // height
        $this->height = (int)$this->height;
        if ($this->height < 0) {
            throw new InvalidConfigException('height');
        }

        // noimage
        $this->noimage = (bool)$this->noimage;

        // watermark
        $this->watermark = trim($this->watermark);
        if ($this->noimage || $this->watermark === '') {
            $this->watermark = null;
        }

        // watermarkOpacity
        $this->watermarkOpacity = (float)$this->watermarkOpacity;
        if ($this->watermarkOpacity < 0 || $this->watermarkOpacity > 1) {
            throw new InvalidConfigException('watermarkOpacity');
        }

        // format
        $this->format = trim($this->format);
        if ($this->format === '') {
            throw new InvalidConfigException('format');
        }

        // quality
        $this->quality = (float)$this->quality;
        if ($this->quality < 0 || $this->quality > 1) {
            throw new InvalidConfigException('quality');
        }

        // строим путь файла в кеше
        $this->_path = preg_replace(
            '~^(.+)\.[^\.]+$~ui',
            sprintf('${1}~%dx%d%s.%s', $this->width, $this->height, !empty($this->watermark) ? '~w' : '', $this->format),
            $this->noimage ? $this->src->name : $this->src->path
        );
    }

    /**
     * Проверяет актуальность превью.
     *
     * @return boolean true если существует и дата изменения не раньше чем у исходного
     */
    public function isValid()
    {
        return $this->exists && $this->mtime >= $this->src->mtime;
    }
}