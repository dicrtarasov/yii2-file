<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 05.01.22 01:42:48
 */

/** @noinspection PhpUsageOfSilenceOperatorInspection */
declare(strict_types = 1);
namespace dicr\file;

use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

use function ftp_close;
use function in_array;
use function is_resource;

/**
 * Файловая система FTP.
 *
 * Жуткая, медленная и не все операции поддерживает.
 *
 * @see http://php.net/manual/en/context.ftp.php
 * @see http://php.net/manual/ru/wrappers.ftp.php
 * @see http://php.net/manual/ru/ref.ftp.php
 */
class FtpFileStore extends LocalFileStore
{
    /** @var string сервер */
    public string $host;

    /** @var int порт сервера */
    public int $port = 21;

    /** @var string логин пользователя */
    public string $username = 'anonymous';

    /** @var string пароль для парольной авторизации */
    public string $password;

    /** @var int таймаут сетевых операций */
    public int $timeout = 90;

    /** @var resource connection */
    private $connection;

    /**
     * @inheritdoc
     * @throws StoreException
     */
    public function init(): void
    {
        if (empty($this->host)) {
            throw new InvalidConfigException('host');
        }

        if ($this->port < 1) {
            throw new InvalidConfigException('port');
        }

        $this->connection = @ftp_connect($this->host, $this->port, $this->timeout);

        if (! is_resource($this->connection)) {
            throw new StoreException('ошибка подключения к серверу', new StoreException(''));
        }

        if (! isset($this->username)) {
            throw new InvalidConfigException('username');
        }

        if (! @ftp_login($this->connection, $this->username, $this->password)) {
            throw new StoreException('ошибка авторизации');
        }

        // Allow overwriting of already existing files on remote server
        /** @noinspection OffsetOperationsInspection */
        if (! isset($this->context['ftp']['overwrite'])) {
            /** @noinspection OffsetOperationsInspection */
            $this->context['ftp']['overwrite'] = true;
        }

        parent::init();
    }

    /**
     * @inheritDoc
     *
     * Переопределяем родительский метод для отмены проверок пути.
     */
    public function setPath(string $path): static
    {
        $this->_path = '/' . $this->normalizePath($path);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function list(array|string $path, array $filter = []): array
    {
        $path = $this->filterPath($path);

        // для FTP нужно проверять существование директории, иначе всегда возвращает пустой список
        if (! $this->exists($path) || ! $this->isDir($path)) {
            throw new StoreException('not a directory: ' . $this->normalizePath($path));
        }

        $dir = @opendir($this->absolutePath($path), /** @scrutinizer ignore-type */ $this->context);
        if ($dir === false) {
            $this->throwLastError('Чтение каталога', $this->absolutePath($path));
        }

        $files = [];

        try {
            while (true) {
                $item = readdir($dir);
                if ($item === false) {
                    break;
                }

                /** @noinspection PhpConditionAlreadyCheckedInspection */
                if (in_array($item, ['', '.', '..'], true)) {
                    continue;
                }

                $file = $this->file($this->childname($path, $item));
                if ($this->fileMatchFilter($file, $filter)) {
                    $files[] = $file;
                }

                if ($this->isDir($file->path)) {
                    /** @noinspection SlowArrayOperationsInLoopInspection */
                    $files = array_merge($files, $this->list($file->path, $filter));
                }
            }
        } finally {
            if (! empty($dir)) {
                closedir($dir);
            }
        }

        return self::sortByName($files);
    }

    /**
     * @inheritdoc
     */
    public function absolutePath(array|string $path): string
    {
        return 'ftp://' . $this->username . ':' . $this->password . '@' . $this->host . ':' . $this->port .
            $this->relativePath($path);
    }

    /**
     * Возвращает относительный путь
     *
     * @noinspection PhpMemberCanBePulledUpInspection
     */
    public function relativePath(array|string $path): string
    {
        return parent::absolutePath($path);
    }

    /**
     * @inheritDoc
     */
    public function size(array|string $path): int
    {
        $size = @ftp_size($this->connection, $this->relativePath($path));
        if ($size < 0) {
            $this->throwLastError('Получение размера файла', $this->absolutePath($path));
        }

        return $size;
    }

    /**
     * @inheritDoc
     */
    public function mtime(array|string $path): int
    {
        $time = @ftp_mdtm($this->connection, $this->relativePath($path));
        if ($time < 0) {
            $this->throwLastError('Получения времени модификации файла', $this->absolutePath($path));
        }

        return $time;
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException
     */
    public function mimeType(array|string $path): string
    {
        throw new NotSupportedException('mimeType');
    }

    /**
     * @inheritdoc
     */
    public function rename(array|string $path, array|string $newpath): static
    {
        $path = $this->filterRootPath($path);
        $newpath = $this->filterRootPath($newpath);

        $relPath = $this->relativePath($path);
        $relNew = $this->relativePath($newpath);

        if ($relPath === $relNew) {
            return $this;
        }

        $this->checkDir($this->dirname($newpath));

        if (! @ftp_rename($this->connection, $relPath, $relNew)) {
            $this->throwLastError('Переименование файла', $this->absolutePath($newpath));
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function mkdir(array|string $path): static
    {
        $path = $this->filterRootPath($path);

        if ($this->exists($path)) {
            throw new StoreException('уже существует: ' . $this->absolutePath($path));
        }

        if (@ftp_mkdir($this->connection, $this->relativePath($path)) === false) {
            $this->throwLastError('Создание директории', $this->absolutePath($path));
        }

        $this->setPublic($path, $this->public);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setPublic(array|string $path, bool $public): static
    {
        $path = $this->filterRootPath($path);
        $perms = $this->permsByPublic($this->isDir($path), $public);

        if (@ftp_chmod($this->connection, $perms, $this->relativePath($path)) === false) {
            $this->throwLastError('Установка прав доступа', $this->absolutePath($path));
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException
     */
    public function isPublic(array|string $path): bool
    {
        throw new NotSupportedException('isPublic');
    }

    /**
     * @inheritdoc
     */
    protected function unlink(array|string $path): static
    {
        $this->filterRootPath($path);

        if (! @ftp_delete($this->connection, $this->relativePath($path))) {
            $this->throwLastError('Удаление файла', $this->absolutePath($path));
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function rmdir(array|string $path): static
    {
        $this->filterRootPath($path);

        if (! @ftp_rmdir($this->connection, $this->relativePath($path))) {
            $this->throwLastError('Удаление директории', $this->absolutePath($path));
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        if (! empty($this->connection)) {
            @ftp_close($this->connection);
        }
    }
}
