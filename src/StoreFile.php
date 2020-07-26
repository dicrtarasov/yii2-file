<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 26.07.20 06:10:10
 */

declare(strict_types = 1);

namespace dicr\file;

use InvalidArgumentException;
use yii\base\InvalidConfigException;
use function is_resource;

/**
 * Файл хранилища файлов.
 * Все операции выполняются через перенаправления к AbstractStore.
 *
 * @property-read AbstractFileStore $store хранилище
 * @property string $path относительный путь (rw)
 * @property-read StoreFile|null $parent родительский каталог (dirname)
 * @property string $name имя файла (basename)
 * @property-read string $absolutePath абсолютный путь
 * @property-read string|null $url абсолютный URL
 * @property bool $public публичный доступ
 * @property-read bool $hidden скрытый
 * @property string $contents содержимое в виде строки (rw)
 * @property resource $stream содержимое в виде потока (rw)
 * @property-read StoreFile[] $list
 */
class StoreFile extends AbstractFile
{
    /** @var string регулярное выражение имени файла со служебным префиксом */
    protected const STORE_PREFIX_REGEX = '~^\.?([^\~]+)\~(\d+)\~(.+)$~u';

    /** @var AbstractFileStore */
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
     * @param array $config
     */
    public function __construct(AbstractFileStore $store, $path, array $config = [])
    {
        if ($store === null) {
            throw new InvalidArgumentException('store');
        }

        // store необходимо установить до установки пути, потому что parent::__construct
        // использует normalizePath
        $this->_store = $store;

        parent::__construct($path, $config);
    }

    /**
     * Добавляет к имени файла служебный префикс хранилища файлов.
     * Существующий префикс удаляется.
     *
     * @param string $attribute аттрибут модели
     * @param int $pos порядок сортировки
     * @param string $name пользовательское имя файла
     * @return string имя файла со служебным префиксом.
     */
    public static function createStorePrefix(string $attribute, int $pos, string $name) : string
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
    public static function removeStorePrefix(string $name) : string
    {
        $matches = null;
        if (preg_match(self::STORE_PREFIX_REGEX, $name, $matches)) {
            $name = $matches[3];
        }

        return $name;
    }

    /**
     * Возвращает хранилище
     *
     * @return AbstractFileStore
     */
    public function getStore() : AbstractFileStore
    {
        return $this->_store;
    }

    /**
     * Возвращает родительскую директорию.
     *
     * @return static|null
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function getParent() : ?self
    {
        if ($this->_path === '') {
            return null;
        }

        return $this->_store->file($this->_store->dirname($this->_path));
    }

    /**
     * @inheritDoc
     * @throws StoreException
     */
    public function getName(array $options = []) : string
    {
        $name = $this->_store->basename($this->_path);

        if (! empty($options['removePrefix'])) {
            $name = static::removeStorePrefix($name);
        }

        if (! empty($options['removeExt'])) {
            $name = static::removeExtension($name);
        }

        return $name;
    }

    /**
     * @inheritDoc
     * @throws StoreException
     */
    protected function normalizePath($path) : string
    {
        return $this->_store->normalizePath($path);
    }

    /**
     * @inheritDoc
     */
    public function getExists() : bool
    {
        return $this->_store->exists($this->_path);
    }

    /**
     * @inheritDoc
     */
    public function getIsDir() : bool
    {
        return $this->_store->isDir($this->_path);
    }

    /**
     * @inheritDoc
     */
    public function getIsFile() : bool
    {
        return $this->_store->isFile($this->_path);
    }

    /**
     * @inheritDoc
     */
    public function getSize() : int
    {
        return $this->_store->size($this->_path);
    }

    /**
     * @inheritDoc
     */
    public function getMtime() : int
    {
        return $this->_store->mtime($this->_path);
    }

    /**
     * @inheritDoc
     */
    public function getMimeType() : string
    {
        return $this->_store->mimeType($this->_path);
    }

    /**
     * @inheritDoc
     */
    public function getContents() : string
    {
        return $this->_store->readContents($this->_path);
    }

    /**
     * @inheritDoc
     */
    public function getStream()
    {
        return $this->_store->readStream($this->_path);
    }

    /**
     * Переименовывает файл (только имя), в том же каталоге.
     *
     * @param string $name новое имя
     * @return $this
     * @throws StoreException
     */
    public function setName(string $name) : self
    {
        // получаем новое имя файла
        $name = $this->_store->basename($name);
        if ($name === '') {
            throw new InvalidArgumentException('name');
        }

        // переименовываем
        $this->setPath($this->_store->childname($this->_store->dirname($this->_path), $name));

        return $this;
    }

    /**
     * Устанавливает путь.
     *
     * @param string|string[] $path new path
     * @return $this
     * @throws StoreException
     */
    public function setPath($path) : self
    {
        $path = $this->normalizePath($path);

        if (! empty($this->_path) && $path !== $this->_path) {
            $this->_store->rename($this->_path, $path);
            $this->_path = $path;
            $this->_absolutePath = null;
            $this->_absoluteUrl = null;
        }

        return $this;
    }

    /**
     * Возвращает абсолютный путь.
     *
     * @return string
     */
    public function getAbsolutePath() : string
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
     * @throws StoreException
     */
    public function getUrl() : ?string
    {
        if (! isset($this->_absoluteUrl)) {
            $this->_absoluteUrl = $this->_store->url($this->_path);
        }

        return $this->_absoluteUrl;
    }

    /**
     * Возвращает дочерний файл с путем относительно данной директории.
     *
     * @param string|string[] $path
     * @return static
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function child($path) : self
    {
        return $this->_store->file($this->_store->childname($this->_path, $path));
    }

    /**
     * Возвращает список файлов директории
     *
     * @param array $options опции и фильтры {@link AbstractFileStore::list}
     * @return static[]
     * @throws StoreException
     */
    public function getList(array $options = []) : array
    {
        return $this->_store->list($this->_path, $options);
    }

    /**
     * Возвращает флаг скрытого файла
     *
     * @throw \dicr\file\StoreException если не существует
     * @return bool
     * @throws StoreException
     */
    public function getHidden() : bool
    {
        return $this->_store->isHidden($this->_path);
    }

    /**
     * Возвращает флаг публичного доступа
     *
     * @return bool
     * @throws StoreException не существует
     */
    public function getPublic() : bool
    {
        return $this->_store->isPublic($this->_path);
    }

    /**
     * Устанавливает флаг публичного доступа
     *
     * @param bool $public
     * @return $this
     * @throws StoreException не существует
     */
    public function setPublic(bool $public) : self
    {
        $this->_store->setPublic($this->_path, $public);

        return $this;
    }

    /**
     * Записывает содержимое файла из строки
     *
     * @param string $contents
     * @return $this
     * @throws StoreException
     */
    public function setContents(string $contents) : self
    {
        $this->_store->writeContents($this->_path, $contents);
        return $this;
    }

    /**
     * Сохраняет содержимое файла из потока
     *
     * @param resource $stream
     * @return $this
     * @throws StoreException
     */
    public function setStream($stream) : self
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('stream');
        }

        $this->_store->writeStream($this->_path, $stream);

        return $this;
    }

    /**
     * Копирует файл.
     *
     * @param $path
     * @return static новый файл
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function copy($path) : self
    {
        $this->_store->copy($this->_path, $path);

        return $this->_store->file($path);
    }

    /**
     * Создает директорию.
     *
     * @return $this
     * @throws StoreException
     */
    public function mkdir() : self
    {
        $this->_store->mkdir($this->_path);

        return $this;
    }

    /**
     * Проверяет создает родительскую директорию.
     *
     * @return $this
     * @throws StoreException
     */
    public function checkDir() : self
    {
        $this->_store->checkDir($this->_store->dirname($this->_path));

        return $this;
    }

    /**
     * Импорт файла в хранилище
     *
     * @param string|string[]|AbstractFile $src импортируемый файл
     * @param array $options опции
     *  - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @return $this
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function import($src, array $options = []) : self
    {
        $this->_store->import($src, $this->_path, $options);
        return $this;
    }

    /**
     * Удаляет файл
     *
     * @return $this
     * @throws StoreException
     */
    public function delete() : self
    {
        $this->_store->delete($this->_path);

        return $this;
    }

    /**
     * Создает превью файла.
     *
     * @param array $config опции ThumbFile
     * @return ThumbFile|null
     * - если thumbFileConfig не настроен, то false
     * - если файл не существует и не настроен noimage, то null
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function thumb(array $config = []) : ?ThumbFile
    {
        return $this->store->thumb($this, $config);
    }

    /**
     * Очищает все превью файла.
     *
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function clearThumb() : self
    {
        $this->store->clearThumb($this);
        return $this;
    }
}
