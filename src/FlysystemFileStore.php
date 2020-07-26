<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 26.07.20 05:47:11
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
class FlysystemFileStore extends AbstractFileStore
{
    /** @var Filesystem|callable */
    public $flysystem;

    /**
     * @inheritdoc
     */
    public function init() : void
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
     * @param bool $public
     * @return string \League\Flysystem\AdapterInterface::VISIBILITY_PUBLIC
     */
    protected static function access2visibility(bool $public) : string
    {
        return $public ? AdapterInterface::VISIBILITY_PUBLIC : AdapterInterface::VISIBILITY_PRIVATE;
    }

    /**
     * Возвращает адаптер
     *
     * @return AdapterInterface|null
     */
    public function getAdapter() : ?AdapterInterface
    {
        if (is_object($this->flysystem) && method_exists($this->flysystem, 'getAdapter')) {
            return $this->flysystem->getAdapter();
        }

        return null;
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function list($path, array $options = []) : array
    {
        $path = $this->normalizePath($path);

        if (! $this->exists($path)) {
            return [];
        }

        try {
            $items = $this->flysystem->listContents($path, $options['recursive'] ?? false);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        /** @var StoreFile[] $files */
        $files = [];
        foreach ($items as $item) {
            // создаем файл
            $file = $this->file($item['path']);

            // фильтруем
            if ($this->fileMatchFilter($file, $options)) {
                $files[] = $file;
            }
        }

        return self::sortByName($files);
    }

    /**
     * @inheritdoc
     */
    public function exists($path) : bool
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
    public function isDir($path) : bool
    {
        return $this->getType($path) === 'dir';
    }

    /**
     * Возвращает тип файл/директория
     *
     * @param string|array $path
     * @return string dir|file
     * @throws StoreException
     */
    public function getType($path) : string
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getMetadata($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['type'] ?? false;
        }

        if (empty($ret)) {
            throw new StoreException('Ошибка получения типа файла: ' . $path);
        }

        return $ret;
    }

    /**
     * @inheritdoc
     */
    public function isFile($path) : bool
    {
        return $this->getType($path) === 'file';
    }

    /**
     * @inheritdoc
     */
    public function isPublic($path) : bool
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
    protected static function visibility2access(string $visibility) : bool
    {
        return $visibility === AdapterInterface::VISIBILITY_PUBLIC;
    }

    /**
     * @inheritdoc
     */
    public function setPublic($path, bool $public) : parent
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
    public function size($path) : int
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
    public function mtime($path) : int
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
    public function mimeType($path) : string
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
    public function readContents($path) : string
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
    public function writeContents($path, string $contents) : int
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
    public function readStream($path)
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
    public function writeStream($path, $stream) : int
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
    public function copy($path, $newpath) : AbstractFileStore
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
     * @throws StoreException
     */
    public function absolutePath($path) : string
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
    public function rename($path, $newpath) : parent
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
    public function mkdir($path) : parent
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
    protected function unlink($path) : parent
    {
        $path = $this->normalizePath($this->filterRootPath($path));

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
    protected function rmdir($path) : parent
    {
        $path = $this->normalizePath($this->filterRootPath($path));

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
