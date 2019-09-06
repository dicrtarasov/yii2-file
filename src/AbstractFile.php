<?php
namespace dicr\file;

use yii\base\BaseObject;

/**
 * Абстракция файл/директория.
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
     * Возвращает путь
     *
     * @return string
     */
    public function getPath()
    {
        return $this->_path;
    }

    /**
     * Нормализация пути.
     *
     * @param string|string[] $path
     * @return string
     */
    abstract protected function normalizePath($path);

    /**
     * Возвращает имя файла.
     *
     * @param array $options
     * - removePrefix - удаляет служебный префикс позиции файла, если имеется
     * - removeExt - удаляет расширение если имеется
     *
     * @return string basename
     */
    abstract public function getName(array $options = []);

    /**
     * Возвращает флаг существования файла.
     *
     * @throws StoreException
     * @return bool
     */
    abstract public function getExists();

    /**
     * Возвращает признак директории.
     *
     * @throws StoreException
     * @return boolean
     */
    abstract public function getIsDir();

    /**
     * Возвращает признак файла.
     *
     * @throws StoreException
     * @return boolean
     */
    abstract public function getIsFile();

    /**
     * Возвращает размер.
     *
     * @throws \dicr\file\StoreException
     * @return int размер в байтах
     */
    abstract public function getSize();

    /**
     * Возвращает время изменения файла.
     *
     * @throws StoreException
     * @return int timestamp
     */
    abstract public function getMtime();

    /**
     * Возвращает Mime-ип файла.
     *
     * @throws StoreException
     * @return string
     */
    abstract public function getMimeType();

    /**
     * Сравнивает Mime-тип файла.
     *
     * @param string $type mime-тип с импользованием шаблонов (image/png, text/*)
     * @return boolean
     */
    public function matchMimeType(string $type)
    {
        $regex = '~^' . str_replace(['/', '*'], ['\\/', '.+'], $type) . '$~uism';
        return (bool)preg_match($this->mimeType, $regex);
    }

    /**
     * Возвращает содержимое файла.
     *
     * @throws \dicr\file\StoreException
     * @return string
     */
    abstract public function getContents();

    /**
     * Возвращает контент в виде потока.
     *
     * @throws StoreException
     * @return resource
     */
    abstract public function getStream();

    /**
     * Возвращает имя файла без расширения.
     *
     * @param string $name
     * @return string
     */
    protected static function removeExtension(string $name)
    {
        if (!empty($name)) {
            $locale = setlocale(LC_ALL, '0');
            setlocale(LC_ALL, 'ru_RU.UTF-8');
            $name = pathinfo($name, PATHINFO_FILENAME);
            setlocale(LC_ALL, $locale);
        }

        return $name;
    }

    /**
     * Конвертирует в строку.
     *
     * @return string path
     */
    public function __toString()
    {
        return $this->_path;
    }
}
