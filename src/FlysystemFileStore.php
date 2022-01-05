<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 06.01.22 00:58:52
 */

declare(strict_types = 1);
namespace dicr\file;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use yii\base\InvalidConfigException;

use function call_user_func;
use function clearstatcache;
use function is_callable;
use function strlen;

/**
 * FileStore based on Flysystem adapters.
 *
 * @see http://flysystem.thephpleague.com/docs/
 */
class FlysystemFileStore extends FileStore
{
    public Closure|Filesystem $flySystem;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (is_callable($this->flySystem)) {
            $this->flySystem = call_user_func($this->flySystem, $this);
        }

        parent::init();
    }

    /**
     * Конвертирует тип доступа public в Flysystem visibility type
     *
     * @param bool $public true - публичный, false - приватный
     * @return string Visibility
     */
    protected static function access2visibility(bool $public): string
    {
        return $public ? Visibility::PUBLIC : Visibility::PRIVATE;
    }

    /**
     * Возвращает тип доступа (публичность) по типу Flysystem
     *
     * @param string $visibility \League\Flysystem\AdapterInterface::VISIBILITY_*
     * @return bool флаг public
     */
    protected static function visibility2access(string $visibility): bool
    {
        return $visibility === Visibility::PUBLIC;
    }

    /**
     * @inheritdoc
     */
    public function list(array|string $path, array $filter = []): array
    {
        $path = $this->normalizePath($path);

        if (! $this->exists($path)) {
            throw new StoreException('Директория не существует: ' . $path);
        }

        try {
            return $this->flySystem
                ->listContents($path, $filter['recursive'] ?? false)
                ->sortByPath()
                ->map(fn(StorageAttributes $attributes) => $this->file($attributes->path()))
                ->filter(fn(File $file) => $this->fileMatchFilter($file, $filter))
                ->toArray();
        } catch (FilesystemException $ex) {
            throw new StoreException('Ошибка чтения директории: ' . $path, $ex);
        }
    }

    /**
     * @inheritdoc
     * @throws StoreException|InvalidConfigException
     */
    public function exists(array|string $path): bool
    {
        $path = $this->normalizePath($path);

        try {
            return $this->flySystem->fileExists($path) || $this->isDir($path);
        } catch (FilesystemException|UnableToRetrieveMetadata $ex) {
            throw new StoreException('Ошибка определения файла: ' . $path, $ex);
        }
    }

    /**
     * Возвращает тип файл/директория
     *
     * @return string dir|file
     * @throws StoreException|InvalidConfigException
     */
    public function getType(array|string $path): string
    {
        $path = $this->normalizePath($path);

        $parent = $this->file($path)->parent;
        if ($parent === null) {
            throw new StoreException('Ошибка получения директории: ' . $path);
        }

        try {
            $listing = $this->flySystem->listContents($parent->path, false);

            /** @var StorageAttributes $item */
            foreach ($listing as $item) {
                if ($item->path() === $path) {
                    return $item->isDir() ? File::TYPE_DIR : File::TYPE_FILE;
                }
            }
        } catch (FilesystemException $ex) {
            throw new StoreException('Ошибка получения типа файла', $ex);
        }

        throw new StoreException('Не удалось определить тип файла: ' . $path);
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function isFile(array|string $path): bool
    {
        try {
            return $this->getType($path) === File::TYPE_FILE;
        } catch (StoreException) {
            // для несуществующих файлов выбрасывается исключение
            return false;
        }
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function isDir(array|string $path): bool
    {
        try {
            return $this->getType($path) === File::TYPE_DIR;
        } catch (StoreException) {
            // для несуществующих файлов выбрасывается исключение
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function isPublic(array|string $path): bool
    {
        $path = $this->normalizePath($path);

        try {
            $visibility = $this->flySystem->visibility($path);

            return self::visibility2access($visibility);
        } catch (FilesystemException|UnableToRetrieveMetadata $ex) {
            throw new StoreException('Ошибка получения прав файла: ' . $path, $ex);
        }
    }

    /**
     * @inheritdoc
     * @param string[]|string $path
     */
    public function setPublic(array|string $path, bool $public): static
    {
        $path = $this->normalizePath($path);
        $visibility = self::access2visibility($public);

        try {
            $this->flySystem->setVisibility($path, $visibility);

            clearstatcache(true, $path);
        } catch (FilesystemException|UnableToSetVisibility $ex) {
            throw new StoreException('Ошибка установки прав на файл: ' . $path, $ex);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function size(array|string $path): int
    {
        $path = $this->normalizePath($path);

        try {
            return $this->flySystem->fileSize($path);
        } catch (FilesystemException|UnableToRetrieveMetadata $ex) {
            throw new StoreException('Ошибка определения размера файла: ' . $path, $ex);
        }
    }

    /**
     * @inheritdoc
     */
    public function mtime(array|string $path): int
    {
        $path = $this->normalizePath($path);

        try {
            return $this->flySystem->lastModified($path);
        } catch (FilesystemException|UnableToRetrieveMetadata $ex) {
            throw new StoreException('Ошибка определения времени модификации файла: ' . $path, $ex);
        }
    }

    /**
     * @inheritdoc
     */
    public function mimeType(array|string $path): string
    {
        $path = $this->normalizePath($path);

        try {
            return $this->flySystem->mimeType($path);
        } catch (FilesystemException|UnableToRetrieveMetadata $ex) {
            throw new StoreException('Ошибка определения mime-типа файла: ' . $path, $ex);
        }
    }

    /**
     * @inheritdoc
     */
    public function readContents(array|string $path): string
    {
        $path = $this->normalizePath($path);

        try {
            return $this->flySystem->read($path);
        } catch (FilesystemException|UnableToReadFile $ex) {
            throw new StoreException('Ошибка чтения файла: ' . $path, $ex);
        }
    }

    /**
     * @inheritdoc
     */
    public function writeContents(array|string $path, string $contents): int
    {
        $path = $this->normalizePath($path);

        try {
            $this->flySystem->write($path, $contents);
            clearstatcache(true, $path);
        } catch (FilesystemException|UnableToWriteFile $ex) {
            throw new StoreException('Ошибка записи файла: ' . $path, $ex);
        }

        return strlen($contents);
    }

    /**
     * @inheritdoc
     */
    public function readStream(array|string $path, string $mode = null)
    {
        $path = $this->normalizePath($path);

        try {
            return $this->flySystem->readStream($path);
        } catch (FilesystemException|UnableToReadFile $ex) {
            throw new StoreException('Ошибка чтения файла: ' . $path, $ex);
        }
    }

    /**
     * @inheritdoc
     */
    public function writeStream(array|string $path, $stream): int
    {
        $path = $this->normalizePath($path);

        try {
            $this->flySystem->writeStream($path, $stream);
            clearstatcache(true, $path);
        } catch (FilesystemException|UnableToWriteFile $ex) {
            throw new StoreException('Ошибка записи файла: ' . $path, $ex);
        }

        return 1;
    }

    /**
     * @inheritdoc
     */
    public function copy(array|string $path, array|string $newpath): static
    {
        $path = $this->normalizePath($path);
        $newpath = $this->normalizePath($newpath);

        if ($path === $newpath) {
            throw new StoreException('Копирование файла в себя: ' . $this->absolutePath($path));
        }

        try {
            $this->flySystem->copy($path, $newpath);
            clearstatcache(true, $path);
            clearstatcache(true, $newpath);
        } catch (FilesystemException|UnableToCopyFile $ex) {
            throw new StoreException('Ошибка копирования файла ' . $path . ' в ' . $newpath, $ex);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function absolutePath(array|string $path): string
    {
        return $this->normalizePath($path);
    }

    /**
     * @inheritdoc
     */
    public function rename(array|string $path, array|string $newpath): static
    {
        $path = $this->normalizePath($path);
        $newpath = $this->normalizePath($newpath);

        if ($path === $newpath) {
            return $this;
        }

        try {
            $this->flySystem->move($path, $newpath);
            clearstatcache(true, $path);
            clearstatcache(true, $newpath);
        } catch (FilesystemException|UnableToMoveFile $ex) {
            throw new StoreException('Ошибка переименования файла ' . $path . ' в ' . $newpath, $ex);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function mkdir(array|string $path): static
    {
        $path = $this->normalizePath($path);

        try {
            $this->flySystem->createDirectory($path);
            clearstatcache(true, $path);
        } catch (UnableToCreateDirectory|FilesystemException $ex) {
            throw new StoreException('Ошибка создания директории: ' . $path, $ex);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function unlink(array|string $path): static
    {
        $path = $this->buildPath($this->filterRootPath($path));

        try {
            $this->flySystem->delete($path);
            clearstatcache(true, $path);
        } catch (FilesystemException|UnableToDeleteFile $ex) {
            throw new StoreException('Ошибка удаления файла: ' . $path, $ex);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function rmdir(array|string $path): static
    {
        $path = $this->buildPath($this->filterRootPath($path));

        try {
            $this->flySystem->deleteDirectory($path);
            clearstatcache(true, $path);
        } catch (FilesystemException|UnableToDeleteDirectory $ex) {
            throw new StoreException('Ошибка удаления директории: ' . $path, $ex);
        }

        return $this;
    }
}
