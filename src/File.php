<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 08.02.22 01:25:01
 */

declare(strict_types = 1);
namespace dicr\file;

use InvalidArgumentException;
use Yii;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;

use function is_resource;
use function preg_match;
use function preg_replace;
use function str_replace;

/**
 * Файл хранилища файлов.
 * Все операции выполняются через перенаправления к AbstractStore.
 *
 * @property-read FileStore $store хранилище
 *
 * @property string $path относительный путь файла
 * @property-read string $absolutePath абсолютный путь
 * @property-read ?string $url абсолютный URL
 * @property string $name имя файла без пути (basename)
 * @property-read string|null $extension расширение файла
 * @property-read File|null $parent родительский каталог (dirname)
 * @property-read File[] $list
 * @property-read bool $exists существует
 * @property-read bool $isDir поддерживает листинг
 * @property-read bool $isFile поддерживает получение содержимого
 * @property-read int $size размер в байтах
 * @property-read int $mtime время изменения
 * @property-read string $mimeType MIME-тип содержимого
 * @property bool $public публичный доступ
 * @property-read bool $hidden скрытый
 * @property string $contents содержимое в виде строки
 * @property resource $stream содержимое в виде потока
 */
class File extends BaseObject
{
    public const TYPE_FILE = 'file';

    public const TYPE_DIR = 'dir';

    /** регулярное выражение имени файла со служебным префиксом */
    protected const STORE_PREFIX_REGEX = '~^\.?([^\~]+)\~(\d+)\~(.+)$~u';

    protected FileStore $_store;

    /** путь файла */
    protected string $_path;

    /** кэш абсолютного пути */
    private ?string $_absolutePath = null;

    /** кэш абсолютного URL */
    private ?string $_absoluteUrl = null;

    /**
     * Конструктор
     *
     * @param string[]|string $path относительный путь
     */
    public function __construct(FileStore $store, array|string $path, array $config = [])
    {
        // store необходимо установить до установки пути, потому что normalizePath использует store
        $this->_store = $store;

        $this->_path = $this->normalizePath($path);

        parent::__construct($config);
    }

    /**
     * Возвращает хранилище
     */
    public function getStore(): FileStore
    {
        return $this->_store;
    }

    /**
     * Возвращает путь
     */
    public function getPath(): string
    {
        return $this->_path;
    }

    /**
     * Устанавливает путь.
     *
     * @param string|string[] $path new path
     * @throws StoreException
     */
    public function setPath(array|string $path): static
    {
        $path = $this->normalizePath($path);

        if ($path !== $this->_path) {
            if (! empty($this->_path)) {
                $this->_store->rename($this->_path, $path);
            }

            $this->_path = $path;
            $this->_absolutePath = null;
            $this->_absoluteUrl = null;
        }

        return $this;
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
    public static function createStorePrefix(string $attribute, int $pos, string $name): string
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
    public static function removeStorePrefix(string $name): string
    {
        $matches = null;
        if (preg_match(self::STORE_PREFIX_REGEX, $name, $matches)) {
            $name = $matches[3];
        }

        return $name;
    }

    /**
     * Имя файла.
     *
     * @param $options
     * - removePrefix - удаляет служебный префикс позиции файла, если имеется
     * - removeExt - удаляет расширение если имеется
     */
    public function getName(array $options = []): string
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
     * Переименовывает файл (только имя), в том же каталоге.
     *
     * @param string $name новое имя
     * @throws StoreException
     */
    public function setName(string $name): static
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
     * Нормализация пути.
     *
     * @param string|string[] $path
     */
    protected function normalizePath(array|string $path): string
    {
        return $this->_store->normalizePath($path);
    }

    /**
     * Расширение файла по имени.
     */
    public function getExtension(): string
    {
        $matches = null;

        return preg_match('~^.+\.([^.]+)$~u', $this->name, $matches) ? $matches[1] : '';
    }

    /**
     * Удаляет расширение из имени файла.
     */
    public static function removeExtension(string $name): string
    {
        return preg_replace('~\.[^\.]+$~u', '', $name);
    }

    /**
     * Возвращает флаг существования файла.
     *
     * @throws StoreException
     */
    public function getExists(): bool
    {
        return $this->_store->exists($this->_path);
    }

    /**
     * Возвращает признак директории.
     *
     * @throws StoreException
     */
    public function getIsDir(): bool
    {
        return $this->_store->isDir($this->_path);
    }

    /**
     * Возвращает признак файла.
     *
     * @throws StoreException
     */
    public function getIsFile(): bool
    {
        return $this->_store->isFile($this->_path);
    }

    /**
     * Возвращает размер.
     *
     * @return int размер в байтах
     * @throws StoreException
     */
    public function getSize(): int
    {
        return $this->_store->size($this->_path);
    }

    /**
     * Возвращает время изменения файла.
     *
     * @return int timestamp
     * @throws StoreException
     */
    public function getMtime(): int
    {
        return $this->_store->mtime($this->_path);
    }

    /**
     * Обновляет время модификации.
     *
     * @param int|null $time время, если null, то time()
     * @throws StoreException
     */
    public function touch(?int $time = null): static
    {
        $this->_store->touch($this->_path, $time);

        return $this;
    }

    /**
     * Возвращает Mime-ип файла.
     *
     * @throws StoreException
     */
    public function getMimeType(): string
    {
        return $this->_store->mimeType($this->_path);
    }

    /**
     * Сравнивает Mime-тип файла.
     *
     * @param string $type mime-тип с использованием шаблонов (image/png, text/*)
     */
    public function matchMimeType(string $type): bool
    {
        $regex = '~^' . str_replace(['/', '*'], ['\\/', '.+'], $type) . '$~uism';

        return (bool)preg_match($regex, $this->mimeType);
    }

    /**
     * Возвращает содержимое файла.
     *
     * @throws StoreException
     */
    public function getContents(): string
    {
        return $this->_store->readContents($this->_path);
    }

    /**
     * Записывает содержимое файла из строки
     *
     * @throws StoreException
     */
    public function setContents(string $contents): static
    {
        $this->_store->writeContents($this->_path, $contents);

        return $this;
    }

    /**
     * Возвращает контент в виде потока.
     *
     * @param ?string $mode режим открытия
     * @return resource
     * @throws StoreException
     */
    public function getStream(?string $mode = null)
    {
        return $this->_store->readStream($this->_path, $mode);
    }

    /**
     * Сохраняет содержимое файла из потока
     *
     * @param resource $stream
     * @throws StoreException
     */
    public function setStream($stream): static
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('stream');
        }

        $this->_store->writeStream($this->_path, $stream);

        return $this;
    }

    /**
     * Возвращает абсолютный путь.
     */
    public function getAbsolutePath(): string
    {
        if (! isset($this->_absolutePath)) {
            $this->_absolutePath = $this->_store->absolutePath($this->_path);
        }

        return $this->_absolutePath;
    }

    /**
     * Возвращает url.
     */
    public function getUrl(): ?string
    {
        if (!isset($this->_absoluteUrl)) {
            $this->_absoluteUrl = $this->_store->url($this->_path) ?: false;
        }

        return $this->_absoluteUrl ?: null;
    }

    /**
     * Возвращает родительскую директорию.
     *
     * @throws InvalidConfigException
     */
    public function getParent(): ?self
    {
        if ($this->_path === '') {
            return null;
        }

        return $this->_store->file($this->_store->dirname($this->_path));
    }

    /**
     * Возвращает дочерний файл с путем относительно данной директории.
     *
     * @param string|string[] $path
     * @throws InvalidConfigException
     */
    public function child(array|string $path): self
    {
        return $this->_store->file($this->_store->childname($this->_path, $path));
    }

    /**
     * Возвращает список файлов директории
     *
     * @param array $options опции и фильтры {@link FileStore::list}
     * @return self[]
     * @throws StoreException
     */
    public function getList(array $options = []): array
    {
        return $this->_store->list($this->_path, $options);
    }

    /**
     * Возвращает флаг скрытого файла
     */
    public function getHidden(): bool
    {
        return $this->_store->isHidden($this->_path);
    }

    /**
     * Возвращает флаг публичного доступа
     *
     * @throws StoreException не существует
     */
    public function getPublic(): bool
    {
        return $this->_store->isPublic($this->_path);
    }

    /**
     * Устанавливает флаг публичного доступа
     *
     * @throws StoreException не существует
     */
    public function setPublic(bool $public): static
    {
        $this->_store->setPublic($this->_path, $public);

        return $this;
    }

    /**
     * Импорт файла в хранилище
     *
     * @param string|File|string[] $src импортируемый файл
     * @param array $options опции
     *  - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function import(array|File|string $src, array $options = []): static
    {
        $this->_store->import($src, $this->_path, $options);

        return $this;
    }

    /**
     * Копирует файл.
     *
     * @param string|string[] $path
     * @return self новый файл
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function copy(array|string $path): self
    {
        $this->_store->copy($this->_path, $path);

        return $this->_store->file($path);
    }

    /**
     * Создает директорию.
     *
     * @throws StoreException
     */
    public function mkdir(): static
    {
        $this->_store->mkdir($this->_path);

        return $this;
    }

    /**
     * Проверяет создает родительскую директорию.
     *
     * @throws StoreException
     */
    public function checkDir(): static
    {
        $this->_store->checkDir($this->_store->dirname($this->_path));

        return $this;
    }

    /**
     * Удаляет файл
     *
     * @throws StoreException
     */
    public function delete(): static
    {
        $this->_store->delete($this->_path);

        return $this;
    }

    /**
     * Создает превью файла.
     *
     * @param array $config опции ThumbFile
     * - если thumbFileConfig не настроен, то false
     * - если файл не существует и не настроен noimage, то null
     * @throws InvalidConfigException
     */
    public function thumb(array $config = []): ThumbFile
    {
        return $this->store->thumb($this, $config);
    }

    /**
     * Очищает все превью файла.
     *
     * @throws StoreException
     */
    public function clearThumb(): static
    {
        try {
            $this->store->clearThumb($this);
        } catch (InvalidConfigException) {
            // noop кэш картинок не настроен
        }

        return $this;
    }

    /**
     * CSVFile.
     *
     * @throws InvalidConfigException
     */
    public function csv(array $config = []): CSVFile
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Yii::createObject($config + [
                'class' => CSVFile::class,
                'store' => $this->store,
                'path' => $this->path
            ]);
    }

    /**
     * Конвертирует в строку.
     */
    public function __toString(): string
    {
        return $this->_path;
    }
}
