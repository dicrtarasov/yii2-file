<?php
namespace dicr\file;

use yii\base\BaseObject;
use yii\base\InvalidConfigException;

/**
 * Файл, хранящийся в файловой системе.
 *
 * @property \dicr\file\AbstractFileStore $store хранилище
 * @property string $path относительный путь
 * @property-read string $fullPath полный путь
 * @property-read string $dir полный путь директории
 * @property string $name имя файла (basename)
 * @property-read string|null $url
 *
 * @property-read bool $exists
 *
 * @property-read string $type
 * @property-read bool $isDir
 * @property-read bool $isFile
 *
 * @property string $access
 * @property-read bool $isPublic
 * @property-read bool $isHidden
 *
 * @property-read int $size
 * @property-read int $mtime
 * @property-read string $mimeType
 *
 * @property string $content содержимое файла в виде строки
 * @property resource $stream содержимое файла в виде ресурса
 * @property-read \dicr\filestore\File[] $list
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
class File extends BaseObject
{
    /* тип файла/директория */
    const TYPE_FILE = 'file';
    const TYPE_DIR = 'dir';

    /* тип доступа */
    const ACCESS_PUBLIC = 'public';
    const ACCESS_PRIVATE = 'private';

    /** @var \dicr\file\AbstractFileStore */
    private $_store;

    /** @var string путь файла */
    private $_path;

    /** @var string полный путь */
    private $_fullPath;

    /**
     * Конструктор
     *
     * @param string|array $config если string, то принимается как path
     */
    public function __construct($config=[]) {
        if (is_string($config)) {
            $config = [
                'path' => $config
            ];
        }

        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        if (! isset($this->_path)) {
            throw new InvalidConfigException('path');
        }
    }

    /**
     * Нормализирует относительный путь
     *
     * @param string|array $path
     */
    public function normalizePath($path) {
        return $this->store->normalizeRelativePath($path);
    }

    /**
     * Возвращает хранилище
     *
     * @return \dicr\file\AbstractFileStore
     */
    public function getStore()
    {
        return $this->_store;
    }

    /**
     * Устанавливает хранилище
     *
     * @param \dicr\file\AbstractFileStore $store
     * @return static
     */
    public function setStore(AbstractFileStore $store)
    {
        $this->_store = $store;
        $this->_fullPath = null;
        return $this;
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
     * Устанавливает путь
     *
     * @param string|array $path new path
     * @param bool $move переместить существующий файл
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function setPath($path, bool $move = false)
    {
        $path = $this->normalizePath($path);

        if ($move && $this->path != '' && $this->exists) {
            $this->store->move($this->path, $path);
        }

        $this->_path = $path;
        $this->_fullPath = null;

        return $this;
    }

    /**
     * Возвращает полный путь
     *
     * @throws StoreException
     * @return string
     */
    public function getFullPath()
    {
        if (! isset($this->_fullPath)) {
            $this->_fullPath = $this->store->getFullPath($this->path);
        }

        return $this->_fullPath;
    }

    /**
     * Возвращает полную директорию файла
     *
     * @return string
     */
    public function getDir()
    {
        $ret = @dirname($this->path);
        if ($ret === false) {
            throw new StoreException();
        }
        return $ret;
    }

    /**
     * Возвращает имя файла
     *
     * @param array|null $options - bool $removePrefix удаляить префикс позиции (^(\.tmp)?\d+~)
     *        - bool $removeExt удалить расширение
     * @return string basename
     */
    public function getName(array $options = [])
    {
        $name = basename($this->path);

        if (! empty($options['removePrefix'])) {
            $matches = null;
            if (preg_match('~^(\.tmp)?\d+\~(.+)$~uism', $name, $matches)) {
                $name = $matches[2];
            }
        }

        if (! empty($options['removeExt'])) {
            $matches = null;
            if (preg_match('~^(.+)\.[^\.]+$~uism', $name, $matches)) {
                $name = $matches[1];
            }
        }

        return $name;
    }

    /**
     * Устанавливает имя файла
     *
     * @param string $name новое имя файла
     * @param bool $rename переименовать существующий файл
     * @throws StoreException
     * @return self
     */
    public function setName(string $name, bool $rename = false)
    {
        $name = basename($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name');
        }

        $path = [];

        $dirname = dirname($this->path);
        if (! empty($dirname) && $dirname !== '.' && $dirname !== '/') {
            $path[] = $dirname;
        }

        $path[] = $name;

        return $this->setPath($path, $rename);
    }

    /**
     * Возвращает url
     *
     * @return string|null
     */
    public function getUrl()
    {
        return $this->store->getUrl($this->path);
    }

    /**
     * Возвращает флаг существования файла
     *
     * @throws StoreException
     * @return bool
     */
    public function getExists()
    {
        return $this->store->isExists($this->path);
    }

    /**
     * Возвращает тип файл/директория
     *
     * @throws StoreException
     * @return string TYPE_*
     */
    public function getType()
    {
        return $this->store->getType($this->path);
    }

    /**
     * Возвращает признак директории
     *
     * @throws StoreException
     * @return boolean
     */
    public function getIsDir()
    {
        return $this->type == self::TYPE_DIR;
    }

    /**
     * Возвращает признак файла
     *
     * @throws StoreException
     * @return boolean
     */
    public function getIsFile()
    {
        return $this->type == self::TYPE_FILE;
    }

    /**
     * Возвращает тип доступа
     *
     * @throws StoreException
     * @return string ACCESS_*
     */
    public function getAccess()
    {
        return $this->store->getAccess($this->_path);
    }

    /**
     * Возвращает признак публичного доступа
     *
     * @throws StoreException
     * @return boolean
     */
    public function getIsPublic()
    {
        return $this->access == self::ACCESS_PUBLIC;
    }

    /**
     * Устанавливает тип доступа
     *
     * @param string $access ACCESS_*
     * @throws StoreException
     * @return self
     */
    public function setAccess(string $access)
    {
        $this->store->setAccess($this->path, $access);
        return $this;
    }

    /**
     * Возвращает флаг скрытого файла
     *
     * @return boolean
     */
    public function getIsHidden()
    {
        return $this->store->isHidden($this->path);
    }

    /**
     * Возвращает размер
     *
     * @throws \dicr\file\StoreException
     * @return int размер в байтах
     */
    public function getSize()
    {
        return $this->store->getSize($this->_path);
    }

    /**
     * Возвращает время изменения файла
     *
     * @throws StoreException
     * @return int timestamp
     */
    public function getMtime()
    {
        return $this->store->getMtime($this->_path);
    }

    /**
     * Возвращает Mime-ип файла
     *
     * @throws StoreException
     * @return string
     */
    public function getMimeType()
    {
        return $this->store->getMimeType($this->path);
    }

    /**
     * Возвращает содержимое файла
     *
     * @throws \dicr\file\StoreException
     * @return string
     */
    public function getContents()
    {
        return $this->store->getContents($this->path);
    }

    /**
     * Записывает содержимое файла
     *
     * @param string $contents
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function setContents(string $contents)
    {
        $this->store->setContents($this->path, $contents);
        return $this;
    }

    /**
     * Возвращает контент в виде потока
     *
     * @throws StoreException
     * @return resource
     */
    public function getStream()
    {
        return $this->store->getStream($this->path);
    }

    /**
     * Записать файл из потока
     *
     * @param resource $stream
     * @throws StoreException
     * @return static
     */
    public function setStream($stream)
    {
        $this->store->setStream($this->path, $stream);
        return $this;
    }

    /**
     * Импорт файла в хранилище
     *
     * @param string $src абсолютны путь импортируемого файла
     * @param array $options опции
     *        - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @throws \dicr\file\StoreException
     * @return static
     * @todo move to AbstractStore
     */
    public function import(string $src, array $options = [])
    {
        // проверяем аргументы
        if (empty($src)) {
            throw new \InvalidArgumentException('src');
        }

        if (! file_exists($src)) {
            throw new StoreException('файл не существует: ' . $src);
        }

        if (! @is_file($src)) {
            throw new StoreException('не является файлом: ' . $src);
        }

        // пропускаем старые файлы
        try {
            if (! empty($options['ifModified'] ?? 1) && $this->exists && @filesize($src) === $this->size && @filemtime($src) <= $this->mtime) {
                return $this;
            }
        } catch (\Throwable $ex) {
            if (stream_is_local($this->fullPath)) {
                throw new StoreException($this->path, $ex);
            }
        }

        // получаем содержимое
        $contents = @file_get_contents($src);
        if ($contents === false) {
            throw new StoreException();
        }

        // записываем в текущий файл
        $this->setContents($contents);

        return $this;
    }

    /**
     * Перемещает файл по новому пути
     *
     * @param string|array $path
     * @throws StoreException
     * @return self
     */
    public function move($path)
    {
        return $this->setPath($path, true);
    }

    /**
     * Переименовывает файл (только имя)
     *
     * @param string $name новое имя файла без пути
     * @throws StoreException
     * @return \dicr\file\File
     */
    public function rename(string $name)
    {
        return $this->setName($name, true);
    }

    /**
     * Копирует файл
     *
     * @param string|array $newpath новый путь
     * @throws StoreException
     * @return self новый файл
     */
    public function copy($newpath)
    {
        $this->store->copy($this->path, $newpath);
        return $this->store->file($newpath);
    }

    /**
     * Создает директорию
     *
     * @throws StoreException
     * @return self
     */
    public function mkdir()
    {
        $this->store->mkdir($this->path);
        return $this;
    }

    /**
     * Проверяет создает родительскую директорию
     *
     * @throws StoreException
     * @return self
     */
    public function checkDir()
    {
        $this->store->checkDir($this->dir);
        return $this;
    }

    /**
     * Возвращает дочерний файл с путем относительно данного файла.
     *
     * @param string|array $relpath
     * @return \dicr\file\File
     */
    public function child($relpath)
    {
        return $this->store->child($this->path, $relpath);
    }

    /**
     * Возвращает список файлов директории
     *
     * @param array $options - string|null $regex паттерн имени
     *        - bool|null $dirs true - только директории, false - только файлы
     * @throws StoreException
     * @return self[]
     * @see \dicr\file\FileStoreInterface::list
     */
    public function getList(array $options = [])
    {
        return $this->store->list($this->path, $options);
    }

    /**
     * Удаляет файл
     *
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function delete()
    {
        $this->store->delete($this->path);
        return $this;
    }

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
