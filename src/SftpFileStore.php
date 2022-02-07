<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 08.02.22 01:29:23
 */

/** @noinspection PhpUsageOfSilenceOperatorInspection */
/** @noinspection PhpComposerExtensionStubsInspection */
declare(strict_types = 1);
namespace dicr\file;

use yii\base\InvalidConfigException;

use function in_array;
use function is_resource;
use function readdir;
use function ssh2_disconnect;

/**
 * Файловая система SFTP
 *
 */
class SftpFileStore extends LocalFileStore
{
    /** сервер */
    public string $host;

    /** порт сервера */
    public int $port = 22;

    /** логин пользователя */
    public string $username;

    /** пароль для парольной авторизации */
    public ?string $password = null;

    /** путь к файлу с открытым ключом в формате OpenSSH для авторизации ключом */
    public ?string $pubkeyfile = null;

    /** путь к файлу приватного ключа */
    public ?string $privkeyfile = null;

    /** пароль приватного ключа */
    public ?string $passphrase = null;

    /** @var resource connection */
    private $session;

    /** @var resource SFTP */
    private $sftp;

    /**
     * @inheritDoc
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

        $this->session = ssh2_connect($this->host, $this->port);
        if (! is_resource($this->session)) {
            throw new StoreException('ошибка подключения к серверу', new StoreException());
        }

        if (! isset($this->username)) {
            throw new InvalidConfigException('username');
        }

        if (isset($this->password)) {
            if (! @ssh2_auth_password($this->session, $this->username, $this->password)) {
                throw new StoreException('ошибка авторизации по логину и паролю');
            }
        } elseif (isset($this->pubkeyfile, $this->privkeyfile)) {
            if (! @ssh2_auth_pubkey_file($this->session, $this->username, $this->pubkeyfile, $this->privkeyfile,
                $this->passphrase)) {
                throw new StoreException('ошибка авторизации по открытому ключу');
            }
        } else {
            throw new InvalidConfigException('требуется password или pubkeyfile для авторизации');
        }

        $this->sftp = @ssh2_sftp($this->session);
        if (empty($this->sftp)) {
            throw new StoreException('ошибка инициализации SFTP');
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
     * @throws InvalidConfigException
     */
    public function list(array|string $path, array $filter = []): array
    {
        $absPath = $this->absolutePath($path);

        $dir = @opendir($absPath, /** @scrutinizer ignore-type */ $this->context);
        if ($dir === false) {
            $this->throwLastError('Чтение каталога', $absPath);
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
     * @inheritDoc
     */
    public function absolutePath(array|string $path): string
    {
        return 'ssh2.sftp://' . (int)$this->sftp . $this->relativePath($path);
    }

    /**
     * Возвращает относительный путь
     */
    public function relativePath(array|string $path): string
    {
        return parent::absolutePath($path);
    }

    /**
     * @inheritDoc
     */
    public function setPublic(array|string $path, bool $public): static
    {
        $path = $this->filterRootPath($path);
        $absPath = $this->absolutePath($path);

        if (! $this->exists($path)) {
            throw new StoreException('not exists: ' . $absPath);
        }

        $perms = $this->permsByPublic($this->isDir($path), $public);
        $relativePath = $this->relativePath($path);

        if (! @ssh2_sftp_chmod($this->sftp, $relativePath, $perms)) {
            $this->throwLastError('Установка прав на файл', $absPath);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritDoc
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

        if (! @ssh2_sftp_rename($this->sftp, $relPath, $relNew)) {
            $this->throwLastError('переименование файла', $this->absolutePath($newpath));
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function mkdir(array|string $path): static
    {
        $path = $this->filterRootPath($path);

        if ($this->exists($path)) {
            throw new StoreException('уже существует: ' . $this->absolutePath($path));
        }

        $perms = $this->permsByPublic(true, $this->public);

        if (! @ssh2_sftp_mkdir($this->sftp, $this->relativePath($path), $perms, true)) {
            $this->throwLastError('Создание директории', $this->absolutePath($path));
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function unlink(array|string $path): static
    {
        $this->filterRootPath($path);

        if (! @ssh2_sftp_unlink($this->sftp, $this->relativePath($path))) {
            $this->throwLastError('Удаление файла', $this->absolutePath($path));
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function rmdir(array|string $path): static
    {
        $this->filterRootPath($path);

        if (! @ssh2_sftp_rmdir($this->sftp, $this->relativePath($path))) {
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
        if (! empty($this->session)) {
            @ssh2_disconnect($this->session);
        }
    }
}
