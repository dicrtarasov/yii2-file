<?php
namespace dicr\file;

use yii\base\NotSupportedException;

/**
 * Файл хранилища файлов.
 * Все операции выполняются через перенаправления к AbstractStore.
 *
 * @property-read \dicr\file\AbstractFileStore $store хранилище
 * @property string $path относительный путь (rw)
 * @property-read \dicr\file\StoreFile|null $parent родительский каталог (dirname)
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
    // регулярное выражение имени файла со служебным префиксом
    const STORE_PREFIX_REGEX = '~^\.?([^\~]+)\~(\d+)\~(.+)$~ui';

    /** @var \dicr\file\AbstractFileStore */
    protected $_store;

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
        if ($this->_path == '') {
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
        $path = $this->normalizePath($path);

        if (!empty($this->_path) && $path != $this->_path) {
            $this->_store->rename($this->_path, $path);
            $this->_path = $path;
            $this->_absolutePath = null;
            $this->_absoluteUrl = null;
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

        return $this->_store->file($this->_store->dirname($this->_path));
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getName()
     */
    public function getName(array $options = [])
    {
        $name = $this->_store->basename($this->_path);

        if (!empty($options['removePrefix'])) {
            $name = static::removeStorePrefix($name);
        }

        if (!empty($options['removeExt'])) {
            $name = static::removeExtension($name);
        }

        return $name;
    }

    /**
     * Переименовывает файл (только имя), в том же каталоге.
     *
     * @param string $name новое имя
     * @return $this
     */
    public function setName(string $name)
    {
        // получаем новое имя файла
        $name = $this->_store->basename($name);
        if ($name == '') {
            throw new \InvalidArgumentException('name');
        }

        // переименовываем
        $this->setPath($this->_store->childname($this->_store->dirname($this->_path), $name));

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
            $this->_absolutePath = $this->_store->absolutePath($this->_path);
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
            $this->_absoluteUrl = $this->_store->url($this->_path);
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
        return $this->_store->file($this->_store->childname($this->_path, $path));
    }

    /**
     * Возвращает список файлов директории
     *
     * @param array $options опции и фильтры {@link AbstractFileStore::list}
     * @throws \dicr\file\StoreException
     * @return static[]
     */
    public function getList(array $options = [])
    {
        return $this->_store->list($this->_path, $options);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getExists()
     */
    public function getExists()
    {
        return $this->_store->exists($this->_path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getIsDir()
     */
    public function getIsDir()
    {
        return $this->_store->isDir($this->_path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getIsFile()
     */
    public function getIsFile()
    {
        return $this->_store->isFile($this->_path);
    }

    /**
     * Возвращает флаг скрытого файла
     *
     * @throw \dicr\file\StoreException если не существует
     * @return bool
     */
    public function getHidden()
    {
        return $this->_store->isHidden($this->_path);
    }

    /**
     * Возвращает флаг публичного доступа
     *
     * @throws \dicr\file\StoreException не существует
     * @return bool
     */
    public function getPublic()
    {
        return $this->_store->isPublic($this->_path);
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
        $this->_store->setPublic($this->_path, $public);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getSize()
     */
    public function getSize()
    {
        return $this->_store->size($this->_path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getMtime()
     */
    public function getMtime()
    {
        return $this->_store->mtime($this->_path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getMimeType()
     */
    public function getMimeType()
    {
        return $this->_store->mimeType($this->_path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getContents()
     */
    public function getContents()
    {
        return $this->_store->readContents($this->_path);
    }

    /**
     * Записывает содержимое файла из строки
     *
     * @param string $contents
     * @return $this
     */
    public function setContents(string $contents)
    {
        $this->_store->writeContents($this->_path, $contents);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getStream()
     */
    public function getStream()
    {
        return $this->_store->readStream($this->_path);
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

        $this->_store->writeStream($this->_path, $stream);

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
        $this->_store->copy($this->_path, $path);

        return $this->_store->file($path);
    }

    /**
     * Создает директорию.
     *
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function mkdir()
    {
        $this->_store->mkdir($this->_path);

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
        $this->_store->checkDir($this->_store->dirname($this->_path));

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
        $this->_store->delete($this->_path);

        return $this;
    }

    /**
     * Создает превью файла
     *
     * @param array $options опции Thumbnailer::process
     * @throws \dicr\file\StoreException
     * @throws \yii\base\NotSupportedException
     * @return \dicr\file\ThumbFile
     * @see \dicr\file\Thumbnailer::process()
     */
    public function thumb(array $options=[])
    {
        if (empty($this->_store->thumbnailer)) {
            throw new NotSupportedException('thumbnailer не настроен');
        }

        return $this->_store->thumbnailer->process($this, $options);
    }

    /**
     * Добавляет к имени файла служебный префикс хранилища файлов.
     * Существующий префикс удаляется.
     *
     * @param string $attribute аттрибут модели
     * @param int $pos норядок сортировки
     * @param string $name пользовательское имя файла
     * @return string имя файла со служебным префиксом.
     */
    public static function createStorePrefix(string $attribute, int $pos, string $name)
    {
        // удаляем текущий префикс
        $name = static::removeStorePrefix($name);

        // добавляем служебный префикс
        return sprintf('%s~%d~%s', $attribute, $pos, $name);
    }

    /**
     * Удаляет из имени файла служебный префикс хранилища файлов.
     *
     * @param string $name имя файла
     * @return string оригинальное имя без префикса
     */
    public static function removeStorePrefix(string $name)
    {
        $matches = null;
        if (preg_match(self::STORE_PREFIX_REGEX, $name, $matches)) {
            $name = $matches[3];
        }

        return $name;
    }
}