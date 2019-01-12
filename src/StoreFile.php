<?php
namespace dicr\file;

/**
 * Файл хранилища файлов
 *
 * @property-read \dicr\file\AbstractFileStore $store хранилище
 * @property string $path относительный путь (rw)
 * @property StoreFile|null $parent родительский каталог (dirname)
 * @property string $name имя файла (basename)
 * @property-read string $absolutePath абсолютный путь
 * @property-read string|null $url абсолютный URL
 * @property bool $public публичный доступ
 * @property-read bool $hidden скрытый
 * @property string $contents содержимое в виде строки (rw)
 * @property resource $stream содержимое в виде потока (rw)
 * @property-read \dicr\file\StoreFile[] $list
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class StoreFile extends AbstractFile
{

    /** @var \dicr\file\AbstractFileStore */
    private $_store;

    /** @var string фбсолютный путь */
    private $_absolutePath;

    /**
     * Конструктор
     *
     * @param AbstractFileStore $store
     * @param string|array $path относительный путь
     */
    public function __construct(AbstractFileStore $store, $path, array $config=[])
    {
        if (empty($store)) {
            throw new \InvalidArgumentException('store');
        }

        // store необходимо установить до установки пути, потому что parent::__construct
        // использует normalizePath
        $this->_store = $store;

        parent::__construct($path, $config);
    }

    /**
     * Генерирует ошибку при доступе к корневому каталогу
     *
     * @throws StoreException
     */
    protected function checkRootAccess()
    {
        if ($this->path == '') {
            throw new StoreException('доступ к корневому каталогу');
        }
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
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::normalizePath()
     */
    public function normalizePath($path)
    {
        return $this->store->normalizePath($path);
    }

    /**
     * Устанавливает путь
     *
     * @param string|array $path new path
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function setPath($path)
    {
        $this->checkRootAccess();

        $path = $this->normalizePath($path);
        if ($path == '') {
            throw new \InvalidArgumentException('path');
        }

        if ($path != $this->_path) {
            $this->store->move($this->_path, $path);
            $this->_path = $path;
            $this->_absolutePath = null;
        }

        return $this;
    }

    /**
     * Возвращает родительскую директорию
     *
     * @return static|null
     */
    public function getParent()
    {
        if ($this->path == '') {
            return null;
        }

        return $this->store->file($this->store->dirname($this->path));
    }

    /**
     * Перемещает в другую лиректорию или хранилище.
     *
     * @param StoreFile $parent
     * @throws StoreException
     * @return static (если родительский store такой же, то себя, иначе новый экземпляр из родительского store)
     */
    public function setParent(StoreFile $parent)
    {
        $this->checkRootAccess();

        if (empty($parent)) {
            throw new \InvalidArgumentException('parent');
        }

        // если в том же хранилище, то просто переименовываем в другую директорию и возвращаем себя
        if ($parent->store === $this->store) {

            // устанавливаем новый путь
            $this->path = $this->store->childname($parent->path, $this->name);

            // возвращаем себя
            return $this;
        }

        // если store другой, то перемещение директорий не поддерживаем
        if (! $this->isFile) {
            throw new StoreException('перемещение директори в другое хранилище');
        }

        // путь в новом хранилище
        $newpath = $parent->store->childname($parent->store->dirname($parent->path), $this->store->basename($this->path));

        // копируем в новое хранилище
        $parent->store->writeContents($newpath, $this->contents);

        // удаляем в текущем хранилище
        $this->delete();

        // если у нового хранилища такой же тип файлов, то просто меняем хранилище и возвращаем себя
        if (get_class($this) == get_class($parent)) {
            $this->_store = $parent->store;
            $this->_path = $newpath;
            $this->_absolutePath = null;
            return $this;
        }

        // создаем и возвращаем новый файл из родительского хранилища
        return $parent->store->file($newpath);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getName()
     */
    public function getName(array $options = [])
    {
        $this->checkRootAccess();

        return $this->store->basename($this->path);
    }

    /**
     * Переименовывает файл, в том же каталоге
     *
     * @param string $name новое имя
     * @return static
     */
    public function setName(string $name)
    {
        $this->checkRootAccess();

        // получаем новое имя файла
        $name = $this->store->basename($name);
        if ($name == '') {
            throw new \InvalidArgumentException('name');
        }

        // переименовываем
        $this->path = $this->store->childname($this->store->dirname($this->path), $name);

        return $this;
    }

    /**
     * Возвращает абсолютный путь
     *
     * @throws StoreException
     * @return string
     */
    public function getAbsolutePath()
    {
        $this->checkRootAccess();

        if (! isset($this->_absolutePath)) {
            $this->_absolutePath = $this->store->absolutePath($this->path);
        }

        return $this->_absolutePath;
    }

    /**
     * Возвращает url
     *
     * @return string|null
     */
    public function getUrl()
    {
        $this->checkRootAccess();

        return $this->store->url($this->path);
    }

    /**
     * Возвращает дочерний файл с путем относительно данной директории
     *
     * @param string|array $path
     * @return static
     */
    public function child($path)
    {
        $path = $this->normalizePath($path);

        if ($path == '') {
            throw new \InvalidArgumentException('path');
        }

        return $this->store->file($this->store->childname($this->path, $path));
    }

    // @formatter:off
    /**
     * Возвращает список файлов директории
     *
     * @param array $options
     * - bool|null recursive
     * - string|null $dir - true - только директории, false - толькофайлы
     * - string|null $public - true - публичный доступ, false - приватный доступ
     * - bool|null $hidden - true - скрытые файлы, false - открытые
     * - string|null $regex - регулярная маска имени
     * - callable|null $filter function(StoreFile $file) : bool филььтр элементов
     * @throws StoreException
     * @return static[]
     */
    // @formatter:on
    public function getList(array $options = [])
    {
        return $this->store->list($this->path, $options);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getExists()
     */
    public function getExists()
    {
        $this->checkRootAccess();

        return $this->store->exists($this->path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getIsDir()
     */
    public function getIsDir()
    {
        return $this->store->isDir($this->path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getIsFile()
     */
    public function getIsFile()
    {
        return $this->store->isFile($this->path);
    }

    /**
     * Возвращает флаг скрытого файла
     *
     * @throw new StoreException если не существует
     * @return boolean
     */
    public function getHidden()
    {
        $this->checkRootAccess();

        return $this->store->isHidden($this->path);
    }

    /**
     * Возвращает флаг публичного доступа
     *
     * @throws StoreException не существует
     * @return boolean
     */
    public function getPublic()
    {
        $this->checkRootAccess();

        return $this->store->isPublic($this->path);
    }

    /**
     * Устанавливает флаг публичного доступа
     *
     * @param bool $public
     * @throws StoreException не существует
     * @return static
     */
    public function setPublic(bool $public)
    {
        $this->checkRootAccess();

        $this->store->setPublic($this->path, $public);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getSize()
     */
    public function getSize()
    {
        $this->checkRootAccess();

        return $this->store->size($this->path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getMtime()
     */
    public function getMtime()
    {
        $this->checkRootAccess();

        return $this->store->mtime($this->_path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getMimeType()
     */
    public function getMimeType()
    {
        $this->checkRootAccess();

        return $this->store->mimeType($this->path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getContents()
     */
    public function getContents()
    {
        $this->checkRootAccess();

        return $this->store->readContents($this->path);
    }

    /**
     * Записывает содержимое файла из строки
     *
     * @param string $contents
     * @return static
     */
    public function setContents(string $contents)
    {
        $this->checkRootAccess();

        $this->store->writeContents($this->path, $contents);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getStream()
     */
    public function getStream()
    {
        $this->checkRootAccess();

        return $this->store->readStream($this->path);
    }

    /**
     * Сохраняет содержимое файла из потока
     *
     * @param resource $stream
     * @return static
     */
    public function setStream($stream)
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('stream');
        }

        $this->checkRootAccess();

        $this->store->writeStream($this->path, $stream);

        return $this;
    }

    /**
     * Копирует файл
     *
     * @param string|array $newpath новый путь
     * @throws StoreException
     * @return self новый файл
     */
    public function copy($path)
    {
        $this->checkRootAccess();

        $path = $this->normalizePath($path);

        if ($path == '') {
            throw new \InvalidArgumentException('path');
        }

        $this->store->copy($this->path, $path);

        return $this->store->file($path);
    }

    /**
     * Создает директорию
     *
     * @throws StoreException
     * @return self
     */
    public function mkdir()
    {
        $this->checkRootAccess();

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
        $this->checkRootAccess();

        $this->store->checkDir($this->store->dirname($this->path));

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
        $this->checkRootAccess();

        if (! $this->isFile) {
            throw new StoreException('импорт в директорию: ' . $this->path);
        }

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
            if (! empty($options['ifModified'] ?? 1) && $this->exists && @filesize($src) === $this->size &&
                @filemtime($src) <= $this->mtime) {
                return $this;
            }
        } catch (\Throwable $ex) {
            // для удаленых файлов исключения означают неподдерживаемую функцию
            if (stream_is_local($this->absolutePath)) {
                throw new StoreException($this->path, $ex);
            }
        }

        // получаем содержимое
        $contents = @file_get_contents($src);
        if ($contents === false) {
            throw new StoreException('ошибка чтения: ' . $src);
        }

        // записываем в текущий файл
        $this->contents = $contents;

        return $this;
    }

    /**
     * Удаляет файл
     *
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function delete()
    {
        $this->checkRootAccess();

        $this->store->delete($this->path);

        return $this;
    }
}