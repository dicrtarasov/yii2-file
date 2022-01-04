<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 05.01.22 01:02:51
 */

declare(strict_types = 1);
namespace dicr\file;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use Throwable;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

use function call_user_func;
use function is_array;
use function is_callable;
use function is_object;
use function strlen;

/**
 * FileStore based on Flysystem adapters.
 *
 * @property-read AdapterInterface|null $adapter
 * @see http://flysystem.thephpleague.com/docs/
 */
class FlysystemFileStore extends FileStore
{
    /** @var Filesystem|callable */
    public $flysystem;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (is_callable($this->flysystem)) {
            $this->flysystem = call_user_func(/** @scrutinizer ignore-type */ $this->flysystem, $this);
        }

        if (! ($this->flysystem instanceof Filesystem)) {
            throw new InvalidConfigException('flysystem');
        }

        $config = $this->flysystem->getConfig();
        if (! $config->has('visibility')) {
            $config->set('visibility', self::access2visibility($this->public));
        }

        parent::init();
    }

    /**
     * Конвертирует тип доступа public в Flysystem visibility type
     *
     * @return string \League\Flysystem\AdapterInterface::VISIBILITY_PUBLIC
     */
    protected static function access2visibility(bool $public): string
    {
        return $public ? AdapterInterface::VISIBILITY_PUBLIC : AdapterInterface::VISIBILITY_PRIVATE;
    }

    /**
     * Возвращает адаптер.
     */
    public function getAdapter(): ?AdapterInterface
    {
        return is_object($this->flysystem) && method_exists($this->flysystem, 'getAdapter') ?
            $this->flysystem->getAdapter() : null;
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function list(array|string $path, array $filter = []): array
    {
        $path = $this->normalizePath($path);

        if (! $this->exists($path)) {
            return [];
        }

        try {
            $items = $this->flysystem->listContents($path, $filter['recursive'] ?? false);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        /** @var File[] $files */
        $files = [];
        foreach ($items as $item) {
            // создаем файл
            $file = $this->file($item['path']);

            // фильтруем
            if ($this->fileMatchFilter($file, $filter)) {
                $files[] = $file;
            }
        }

        return self::sortByName($files);
    }

    /**
     * @inheritdoc
     */
    public function exists(array|string $path): bool
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->has($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        return ! empty($ret);
    }

    /**
     * @inheritdoc
     */
    public function isDir(array|string $path): bool
    {
        return $this->getType($path) === 'dir';
    }

    /**
     * Возвращает тип файл/директория
     *
     * @return string dir|file
     * @throws StoreException
     */
    public function getType(array|string $path): string
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getMetadata($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            /** @var array $ret */
            $ret = $ret['type'] ?? false;
        }

        /** @var string|false $ret */
        if (empty($ret)) {
            throw new StoreException('Ошибка получения типа файла: ' . $path);
        }

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function isFile(array|string $path): bool
    {
        return $this->getType($path) === 'file';
    }

    /**
     * @inheritdoc
     */
    public function isPublic(array|string $path): bool
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getVisibility($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (empty($ret)) {
            throw new StoreException($path);
        }

        return self::visibility2access($ret);
    }

    /**
     * Возвращает тип доступа (публичность) по типу Flysystem
     *
     * @param string $visibility \League\Flysystem\AdapterInterface::VISIBILITY_*
     * @return bool флаг public
     */
    protected static function visibility2access(string $visibility): bool
    {
        return $visibility === AdapterInterface::VISIBILITY_PUBLIC;
    }

    /**
     * @inheritdoc
     */
    public function setPublic(array|string $path, bool $public): static
    {
        $path = $this->normalizePath($path);
        $visibility = self::access2visibility($public);

        try {
            $ret = $this->flysystem->setVisibility($path, $visibility);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function size(array|string $path): int
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getSize($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function mtime(array|string $path): int
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getTimestamp($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function mimeType(array|string $path): string
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getMimetype($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function readContents(array|string $path): string
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->read($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function writeContents(array|string $path, string $contents): int
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->put($path, $contents);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        // глючный flysystem возвращает bool вместо size, поэтому костыль
        $ret = strlen($contents);

        $this->clearStatCache($path);

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function readStream(array|string $path, string $mode = null)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->readStream($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function writeStream(array|string $path, $stream): int
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->putStream($path, $stream);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return 1;
    }

    /**
     * @inheritdoc
     * @throws NotSupportedException
     */
    public function copy(array|string $path, array|string $newpath): static
    {
        $path = $this->normalizePath($path);
        $newpath = $this->normalizePath($newpath);

        if ($path === $newpath) {
            throw new StoreException('Копирование файла в себя: ' . $this->absolutePath($path));
        }

        try {
            $ret = $this->flysystem->copy($path, $newpath);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($newpath);

        return $this;
    }

    /**
     * @inheritdoc
     * @throws NotSupportedException
     */
    public function absolutePath(array|string $path): string
    {
        $path = $this->normalizePath($path);

        $adapter = $this->adapter;
        if (isset($adapter) && is_callable([$adapter, 'applyPathPrefix'])) {
            return $adapter->applyPathPrefix($path);
        }

        throw new NotSupportedException('адаптер не поддерживает метод applyPathPrefix');
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
            $ret = $this->flysystem->rename($path, $newpath);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function mkdir(array|string $path): static
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->createDir($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
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
            $ret = $this->flysystem->delete($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function rmdir(array|string $path): static
    {
        $path = $this->buildPath($this->filterRootPath($path));

        try {
            $ret = $this->flysystem->deleteDir($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }
}
