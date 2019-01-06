<?php
namespace dicr\file;

use yii\base\BaseObject;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Файл, хранящийся в файловой системе.
 *
 * @property \dicr\filestore\FileStore $store хранилище с базовым путем
 * @property string $path путь (если задан $store, то относительный, иначе абсолютный)
 * @property-read string $fullPath поный путь относительно $store или $path если $store не задан
 * @property string $name имя файла (basename)
 * @property-read string $dir полный путь директории
 * @property-read string|null $url полный url относительно $store иначе null
 * @property-read bool $exists
 * @property-read bool $readable
 * @property-read bool $writeable
 * @property-read bool $isDir
 * @property-read bool $isFile
 * @property-read int $size
 * @property-read int $time
 *
 * @property-read \dicr\filestore\File[] $list
 * @property string $content содержимое файла в виде строки
 * @property resource $stream содержимое файла в виде ресурса
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
class File extends BaseObject
{

    /** @var \dicr\file\FileStore */
    private $store;

    /** @var string путь файла */
    private $path;

    /** @var string|null кэш fullPath */
    private $_fullPath;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        if (empty($this->path)) {
            throw new InvalidConfigException('path');
        }
    }

    /**
     * Возвращает хранилище
     *
     * @return \dicr\file\FileStore
     */
    public function getStore()
    {
        return $this->store;
    }

    /**
     * Устанавливает хранилище
     *
     * @param FileStore|null $store
     * @return static
     */
    public function setStore(FileStore $store)
    {
        $this->store = $store;
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
        return $this->path;
    }

    /**
     * Устанавливает путь
     *
     * @param string $path new path
     * @param bool $move переместить существующий файл
     * @throws \InvalidArgumentException
     * @return static
     */
    public function setPath(string $path, bool $move = false)
    {
        $path = trim($path, '/');
        if (empty($path)) {
            throw new \InvalidArgumentException('path');
        }

        if ($move && $this->exists) {
            $oldPath = $this->fullPath;
            $this->path = $path;
            $this->_fullPath = null;
            $this->checkDir();
            $newPath = $this->fullPath;
            if ($newPath != $oldPath && ! @rename($oldPath, $newPath)) {
                throw new StoreException(null);
            }
        } else {
            $this->path = $path;
            $this->_fullPath = null;
        }

        return $this;
    }

    /**
     * Перемещает файл по новому пути
     *
     * @param string $path
     * @throws StoreException
     * @return static
     */
    public function move(string $path)
    {
        return $this->setPath($path, true);
    }

    /**
     * Возвращает полный путь
     *
     * @return string
     */
    public function getFullPath()
    {
        if (! isset($this->_fullPath)) {
            $this->_fullPath = implode('/', [
                ! empty($this->store) ? $this->store->path : '',
                $this->path
            ]);
        }

        return $this->_fullPath;
    }

    /**
     * Возвращает url
     *
     * @return string|null
     */
    public function getUrl()
    {
        if (empty($this->store)) {
            return null;
        }

        $url = $this->store->url;
        if (empty($url)) {
            return null;
        }

        $url .= '/' . trim($this->path, '/');
        return $url;
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
     * @return static
     */
    public function setName(string $name, bool $rename = false)
    {
        $name = basename($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name');
        }

        $path = [];

        $dirname = dirname(trim($this->path, '/'));
        if (! empty($dirname) && $dirname !== '.' && $dirname !== '/') {
            $path[] = $dirname;
        }

        $path[] = $name;

        return $this->setPath(implode('/', $path), $rename);
    }

    /**
     * Переименовывает файл (только имя)
     *
     * @param string $name новое имя файла без пути
     * @return \dicr\file\File
     */
    public function rename(string $name)
    {
        return $this->setName($name, true);
    }

    /**
     * Возвращает полную директорию файла
     *
     * @return string
     */
    public function getDir()
    {
        return dirname($this->fullPath);
    }

    /**
     * Проверяет/создает директори файла
     *
     * @throws StoreException
     * @return static
     */
    public function checkDir()
    {
        $dir = $this->dir;
        if (! @file_exists($dir) && ! @mkdir($dir, ! empty($this->store) ? $this->store->dirmode : 0755, true)) {
            throw new StoreException(null);
        }
        return $this;
    }

    /**
     * Возвращает флаг существования файла
     *
     * @return bool
     */
    public function getExists()
    {
        return @file_exists($this->fullPath);
    }

    /**
     * Возвращает флаг доступности для чтения
     *
     * @return bool
     */
    public function getReadable()
    {
        return @is_readable($this->fullPath);
    }

    /**
     * Возвращает флаг доступности для записи
     *
     * @return bool
     */
    public function getWriteable()
    {
        return @is_writable($this->fullPath);
    }

    /**
     * Проверяет флаг директории
     *
     * @return bool
     */
    public function getIsDir()
    {
        return @is_dir($this->fullPath);
    }

    /**
     * Проверяет флаг файла
     *
     * @return bool
     */
    public function getIsFile()
    {
        return @is_file($this->fullPath);
    }

    /**
     * Возвращает размер
     *
     * @throws \dicr\file\StoreException
     * @return int размер в байтах
     */
    public function getSize()
    {
        $size = @filesize($this->fullPath);
        if ($size === false) {
            throw new StoreException(null);
        }
        return $size;
    }

    /**
     * Возвращает время изменения файла
     *
     * @return int
     */
    public function getTime()
    {
        $time = @filemtime($this->fullPath);
        if ($time === false) {
            throw new StoreException(null);
        }
        return $time;
    }

    /**
     * Возвращает дочерний файл с путем относительно данного файла.
     *
     * @param string $relpath
     * @throws \InvalidArgumentException
     * @return \dicr\file\File
     */
    public function child(string $relpath)
    {
        $relpath = trim($relpath, '/');
        if (empty($relpath)) {
            throw new \InvalidArgumentException('relpath');
        }

        return new static([
            'store' => $this->store,
            'path' => $this->path . '/' . $relpath
        ]);
    }

    /**
     * Возвращает список файлов директории
     *
     * @param array $options - string|null $regex паттерн имени
     *        - bool|null $dirs true - только директории, false - только файлы
     * @throws \dicr\file\StoreException
     * @return self[]
     * @see \dicr\file\FileStore::list
     */
    public function getList(array $options = [])
    {
        if (empty($this->store)) {
            throw new InvalidConfigException('store');
        }

        return $this->store->list($this->path, $options);
    }

    /**
     * Возвращает содержимое файла
     *
     * @throws \dicr\file\StoreException
     * @return string|false
     */
    public function getContent()
    {
        $content = @file_get_contents($this->fullPath, false);
        if ($content === false) {
            throw new StoreException(null);
        }
        return $content;
    }

    /**
     * Записывает содержимое файла
     *
     * @param string|resource $content
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function setContent($content)
    {
        $this->checkDir();

        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }

        $fullPath = $this->fullPath;

        if (@file_put_contents($fullPath, (string) $content, false) === false) {
            throw new StoreException(null);
        }

        if (! @chmod($fullPath, ! empty($this->store) ? $this->store->filemode : 0644)) {
            throw new StoreException(null);
        }

        return $this;
    }

    /**
     * Возвращает контент в виде потока
     *
     * @param string $mode
     * @throws StoreException
     * @return resource
     */
    public function getStream(string $mode='rt') {
        $stream = @fopen($this->fullPath, $mode);
        if ($stream === false) {
            throw new StoreException();
        }
        return $stream;
    }

    /**
     * Записать файл из потока
     *
     * @param resource $stream
     * @return self
     */
    public function setStream($stream) {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('stream');
        }

        $content = @stream_get_contents($stream);
        if ($content === false) {
            throw new StoreException();
        }

        $this->setContent($content);
        return $this;
    }

    /**
     * Импорт файла в хранилище
     *
     * @param string $src полный путь импортируемого файла
     * @param array $options опции
     *        - bool $move переместить файл при импорте, иначе скопировать (по-умолчанию false)
     *        - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function import(string $src, array $options = [])
    {
        // проверяем аргументы
        if (empty($src)) {
            throw new \InvalidArgumentException('src');
        }

        if (! @is_file($src)) {
            throw new StoreException('исходный файл не найден: ' . $src);
        }

        // получаем параметры
        $ifModified = ! empty($options['ifModified'] ?? 1);
        $move = ! empty($options['move'] ?? 0);

        // пропускаем старые файлы
        if ($ifModified && $this->exists && @filesize($src) === $this->size && @filemtime($src) <= $this->time) {
            return $this;
        }

        // проверяем существование директории
        $this->checkDir();

        $func = $move ? 'rename' : 'copy';
        $fullPath = $this->fullPath;

        if (! @$func($src, $fullPath)) {
            throw new StoreException(null);
        }

        if (! @chmod($fullPath, ! empty($this->store) ? $this->store->filemode : 0644)) {
            throw new StoreException(null);
        }

        return $this;
    }

    /**
     * Удаляет файл
     *
     * @param bool|null $recursive рекурсивно для директорий
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function delete(bool $recursive = false)
    {
        if ($this->getExists()) {
            if ($this->getIsDir()) {
                if ($recursive) {
                    foreach ($this->list as $file) {
                        $file->delete(true);
                    }

                    if (! @rmdir($this->fullPath)) {
                        throw new StoreException(null);
                    }
                } else {
                    throw new StoreException('delete directory not recursive: ' . $this->getFullPath());
                }
            } elseif (! @unlink($this->getFullPath())) {
                throw new StoreException(null);
            }
        }

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
