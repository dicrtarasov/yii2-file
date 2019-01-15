<?php
namespace dicr\file;

use yii\base\BaseObject;

/**
 * Абстрактный файл
 *
 * @property-read string $path путь файла
 * @property-read string $name имя файла без пути
 * @property-read bool $exists существует
 * @property-read bool $isDir поддерживает листинг
 * @property-read bool $isFile поддерживает получение содержимого
 * @property-read int $size размер в байтах
 * @property-read int $mtime время изменения
 * @property-read string $mimeType MIME-тип содержимого
 * @property-read string $contents содержимое в виде строки
 * @property-read resource $stream содержимое в виде потока
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
abstract class AbstractFile extends BaseObject
{
    /** @var string путь файла */
    protected $_path;

    /**
     * Конструктор
     *
     * @param string|array $path путь файла
     * @param array $config
     */
    public function __construct($path, array $config = [])
    {
        $this->_path = $this->normalizePath($path);

        parent::__construct($config);
    }

    /**
     * Возвращает путь
     *
     * @return string
     */
    public function getPath()
    {
        return $this->_path;
    }

    /**
     * Нормализация пути
     * @param string|array $path
     * @return string
     */
    abstract public function normalizePath($path);

    /**
     * Возвращает имя файла
     *
     * @return string basename
     */
    abstract public function getName();

    /**
     * Возвращает флаг существования файла
     *
     * @throws StoreException
     * @return bool
     */
    abstract public function getExists();

    /**
     * Возвращает признак директории
     *
     * @throws StoreException
     * @return boolean
     */
    abstract public function getIsDir();

    /**
     * Возвращает признак файла
     *
     * @throws StoreException
     * @return boolean
     */
    abstract public function getIsFile();

    /**
     * Возвращает размер
     *
     * @throws \dicr\file\StoreException
     * @return int размер в байтах
     */
    abstract public function getSize();

    /**
     * Возвращает время изменения файла
     *
     * @throws StoreException
     * @return int timestamp
     */
    abstract public function getMtime();

    /**
     * Возвращает Mime-ип файла
     *
     * @throws StoreException
     * @return string
     */
    abstract public function getMimeType();

    /**
     * Возвращает содержимое файла
     *
     * @throws \dicr\file\StoreException
     * @return string
     */
    abstract public function getContents();

    /**
     * Возвращает контент в виде потока
     *
     * @throws StoreException
     * @return resource
     */
    abstract public function getStream();

    /**
     * Конвертирует в строку
     *
     * @return string path
     */
    public function __toString()
    {
        return $this->path;
    }

}
