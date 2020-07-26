<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 26.07.20 05:15:59
 */

declare(strict_types = 1);

namespace dicr\file;

use yii\base\BaseObject;

/**
 * Абстракция файл/директория.
 *
 * @property-read string $path путь файла
 * @property-read string $name имя файла без пути
 * @property-read string|null $extension расширение файла
 * @property-read bool $exists существует
 * @property-read bool $isDir поддерживает листинг
 * @property-read bool $isFile поддерживает получение содержимого
 * @property-read int $size размер в байтах
 * @property-read int $mtime время изменения
 * @property-read string $mimeType MIME-тип содержимого
 * @property-read string $contents содержимое в виде строки
 * @property-read resource $stream содержимое в виде потока
 */
abstract class AbstractFile extends BaseObject
{
    /** @var string путь файла */
    protected $_path;

    /**
     * Конструктор
     *
     * @param string|string[] $path путь файла
     * @param array $config
     */
    public function __construct($path, array $config = [])
    {
        // не предоставляем функцию setPath
        $this->_path = $this->normalizePath($path);

        parent::__construct($config);
    }

    /**
     * Нормализация пути.
     *
     * @param string|string[] $path
     * @return string
     */
    abstract protected function normalizePath($path) : string;

    /**
     * Возвращает имя файла без расширения.
     *
     * @param string $name
     * @return string
     */
    public static function removeExtension(string $name) : string
    {
        return preg_replace('~^(.+)\.[^.]+$~u', '${1}', $name);
    }

    /**
     * Возвращает путь
     *
     * @return string
     */
    public function getPath() : string
    {
        return $this->_path;
    }

    /**
     * Возвращает имя файла.
     *
     * @param array $options
     * - removePrefix - удаляет служебный префикс позиции файла, если имеется
     * - removeExt - удаляет расширение если имеется
     *
     * @return string
     */
    abstract public function getName(array $options = []) : string;

    /**
     * Возвращает расширение файла по имени.
     *
     * @return string
     */
    public function getExtension() : ?string
    {
        $matches = null;
        return preg_match('~^.+\.([^.]+)$~u', $this->name, $matches) ? $matches[1] : '';
    }

    /**
     * Возвращает флаг существования файла.
     *
     * @return bool
     * @throws StoreException
     */
    abstract public function getExists() : bool;

    /**
     * Возвращает признак директории.
     *
     * @return bool
     * @throws StoreException
     */
    abstract public function getIsDir() : bool;

    /**
     * Возвращает признак файла.
     *
     * @return bool
     * @throws StoreException
     */
    abstract public function getIsFile() : bool;

    /**
     * Возвращает размер.
     *
     * @return int размер в байтах
     * @throws StoreException
     */
    abstract public function getSize() : int;

    /**
     * Возвращает время изменения файла.
     *
     * @return int timestamp
     * @throws StoreException
     */
    abstract public function getMtime() : int;

    /**
     * Возвращает Mime-ип файла.
     *
     * @return string
     * @throws StoreException
     */
    abstract public function getMimeType() : string;

    /**
     * Сравнивает Mime-тип файла.
     *
     * @param string $type mime-тип с использованием шаблонов (image/png, text/*)
     * @return bool
     */
    public function matchMimeType(string $type) : bool
    {
        $regex = '~^' . str_replace(['/', '*'], ['\\/', '.+'], $type) . '$~uism';
        return (bool)preg_match($this->mimeType, $regex);
    }

    /**
     * Возвращает содержимое файла.
     *
     * @return string
     * @throws StoreException
     */
    abstract public function getContents() : string;

    /**
     * Возвращает контент в виде потока.
     *
     * @return resource
     * @throws StoreException
     */
    abstract public function getStream();

    /**
     * Конвертирует в строку.
     *
     * @return string path
     */
    public function __toString() : string
    {
        return (string)$this->_path;
    }
}
