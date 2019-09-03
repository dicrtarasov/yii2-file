<?php
namespace dicr\file;

use yii\base\NotSupportedException;

/**
 * Файл хранилища файлов.
 * Все операции выполняются через перенаправления к AbstractStore.
 *
 * @property-read \dicr\file\AbstractFileStore $store хранилище
 * @property string $path относительный путь (rw)
 * @property \dicr\file\StoreFile|null $parent родительский каталог (dirname)
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

    /** @var string кэш абсолютного пути */
    private $_absolutePath;

    /** @var string кэш абсолютного URL */
    private $_absoluteUrl;

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
    protected function normalizePath($path)
    {
        return $this->_store->normalizePath($path);
    }

    /**
     * Устанавливает путь.
     *
     * @param string|string[] $path new path
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function setPath($path)
    {
        $strPath = $this->_store->buildPath($path);
        if ($this->_path !== $strPath) {
            $this->_store->rename($this->_path, $path);
            $this->_path = $strPath;
            $this->_absolutePath = null;
        }

        return $this;
    }

    /**
     * Возвращает родительскую директорию.
     *
     * @return static|null
     */
    public function getParent()
    {
        if ($this->_path == '') {
            return null;
        }

        return $this->store->file($this->store->dirname($this->path));
    }

    /**
     * Перемещает в другую лиректорию или хранилище.
     *
     * @param \dicr\file\StoreFile $parent
     * @throws \dicr\file\StoreException
     * @return $this файл в новом хранилище
     */
    public function setParent(StoreFile $parent)
    {
        if (empty($parent)) {
            throw new \InvalidArgumentException('parent');
        }

        $this->checkRootAccess();

        // если в том же хранилище, то просто переименовываем в другую директорию и возвращаем себя
        if ($parent->_store === $this->_store) {

            // устанавливаем новый путь
            $this->setPath($this->store->childname($parent->path, $this->name));

            // возвращаем себя
            return $this;
        }

        // @TODO если store другой, то перемещение директорий не поддерживаем из-за вложенных файлов
        if (! $this->isFile) {
            throw new NotSupportedException('Перемещение каталога с файлами в другое хранилище пока не поддерживается');
        }

        // файл в новом хранилище
        $newFile = $parent->store->file(
            $parent->store->childname(
                $parent->store->dirname($parent->path),
                $this->store->basename($this->path)
            )
        );

        // копируем в новое хранилище
        $newFile->stream = $this->stream;

        // удаляем в текущем хранилище
        $this->delete();

        // копируем параметры удаленного файла себе
        $this->_store = $newFile->_store;
        $this->_path = $newFile->_path;

        // сбрасываем кэшируемые значения
        $this->_absolutePath = null;

        // возвращаем себя как новый файл
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getName()
     */
    public function getName(array $options = [])
    {
        $name = $this->store->basename($this->path);

        if (!empty($options['removePrefix'])) {
            $name = static::removeNamePrefix($name);
        }

        if (!empty($options['removeExt'])) {
            $locale = setlocale(LC_ALL, '0');
            setlocale(LC_ALL, 'ru_RU.UTF-8');
            $name = pathinfo($name, PATHINFO_FILENAME);
            setlocale(LC_ALL, $locale);
        }

        return $name;
    }

    /**
     * Переименовывает файл, в том же каталоге
     *
     * @param string $name новое имя
     * @return $this
     */
    public function setName(string $name)
    {
        // получаем новое имя файла
        $name = $this->store->basename($name);
        if ($name == '') {
            throw new \InvalidArgumentException('name');
        }

        // переименовываем
        $this->setPath($this->store->childname($this->store->dirname($this->path), $name));

        return $this;
    }

    /**
     * Возвращает абсолютный путь.
     *
     * @throws \dicr\file\StoreException
     * @return string
     */
    public function getAbsolutePath()
    {
        if (! isset($this->_absolutePath)) {
            $this->_absolutePath = $this->store->absolutePath($this->path);
        }

        return $this->_absolutePath;
    }

    /**
     * Возвращает url.
     *
     * @return string|null
     */
    public function getUrl()
    {
        if (!isset($this->_absoluteUrl)) {
            $this->_absoluteUrl = $this->store->url($this->path);
        }

        return $this->_absoluteUrl;
    }

    /**
     * Возвращает дочерний файл с путем относительно данной директории.
     *
     * @param string|string[] $path
     * @return static
     */
    public function child($path)
    {
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
     * @throws \dicr\file\StoreException
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
     * @throw \dicr\file\StoreException если не существует
     * @return bool
     */
    public function getHidden()
    {
        return $this->store->isHidden($this->path);
    }

    /**
     * Возвращает флаг публичного доступа
     *
     * @throws \dicr\file\StoreException не существует
     * @return bool
     */
    public function getPublic()
    {
        return $this->store->isPublic($this->path);
    }

    /**
     * Устанавливает флаг публичного доступа
     *
     * @param bool $public
     * @throws \dicr\file\StoreException не существует
     * @return $this
     */
    public function setPublic(bool $public)
    {
        $this->store->setPublic($this->path, $public);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getSize()
     */
    public function getSize()
    {
        return $this->store->size($this->path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getMtime()
     */
    public function getMtime()
    {
        return $this->store->mtime($this->_path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getMimeType()
     */
    public function getMimeType()
    {
        return $this->store->mimeType($this->path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getContents()
     */
    public function getContents()
    {
        return $this->store->readContents($this->path);
    }

    /**
     * Записывает содержимое файла из строки
     *
     * @param string $contents
     * @return $this
     */
    public function setContents(string $contents)
    {
        $this->store->writeContents($this->path, $contents);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getStream()
     */
    public function getStream()
    {
        return $this->store->readStream($this->path);
    }

    /**
     * Сохраняет содержимое файла из потока
     *
     * @param resource $stream
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function setStream($stream)
    {
        if (! @is_resource($stream)) {
            throw new \InvalidArgumentException('stream');
        }

        $this->store->writeStream($this->path, $stream);

        return $this;
    }

    /**
     * Копирует файл.
     *
     * @param string|string[] $newpath новый путь
     * @throws \dicr\file\StoreException
     * @return static новый файл
     */
    public function copy($path)
    {
        $this->store->copy($this->path, $path);

        return $this->store->file($path);
    }

    /**
     * Создает директорию.
     *
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function mkdir()
    {
        $this->store->mkdir($this->path);

        return $this;
    }

    /**
     * Проверяет создает родительскую директорию.
     *
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function checkDir()
    {
        $this->store->checkDir($this->store->dirname($this->path));

        return $this;
    }

    /**
     * Импорт файла в хранилище
     *
     * @param string|string[]|\dicr\file\AbstractFile $src импорируемый файл
     * @param array $options опции
     *  - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @throws \dicr\file\StoreException
     * @return $this
     * @see AbstractFileStore::import()
     */
    public function import($src, array $options = [])
    {
        $this->_store->import($src, $this->_path, $options);
    }

    /**
     * Удаляет файл
     *
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function delete()
    {
        $this->store->delete($this->path);

        return $this;
    }

    /**
     * Создает превью файла
     *
     * @param array $options
     * @return \dicr\file\ThumbFile
     * @throws \dicr\file\StoreException
     * @throws \yii\base\NotSupportedException
     * @see \dicr\file\Thumbnailer::process()
     */
    public function thumb(array $options=[])
    {
        if (empty($this->store->thumbnailer)) {
            throw new NotSupportedException('thumbnailer не настроен');
        }

        return $this->store->thumbnailer->process($this, $options);
    }

    /**
     * Удаляет из имени файла технический префикс позиции.
     *
     * @param string $name имя файла
     * @return string оригинальное имя без префикса
     */
    public static function removeNamePrefix(string $name)
    {
        $matches = null;
        if (preg_match('~^(\.tmp)?\d+\~(.+)$~uism', $name, $matches)) {
            $name = $matches[2];
        }

        return $name;
    }

    /**
     * Добавляет имени файла временный префикс позиции.
     *
     * Предварительно удаляется существующий префикс.
     *
     * @param string $name
     * @return string
     */
    public static function setTempPrefix(string $name)
    {
        // удаляем текущий префикс
        $name = static::removeNamePrefix($name);

        // добавляем временный префиск
        return sprintf('.tmp%d~%s', rand(100000, 999999), $name);
    }

    /**
     * Добавляет к имени файла служебнй префикс позиции.
     *
     * Существующий префикс удаляется.
     *
     * @param string $name
     * @return string
     */
    public static function setPosPrefix(string $name, int $pos)
    {
        // удаляем текущий префикс
        $name = static::removeNamePrefix($name);

        // добавляем порядковый префиск
        return sprintf('%d~%s', $pos, $name);
    }
}