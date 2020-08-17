<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 17.08.20 22:22:52
 */

/** @noinspection PhpUsageOfSilenceOperatorInspection */
/** @noinspection PhpComposerExtensionStubsInspection */
declare(strict_types = 1);
namespace dicr\file;

use yii\base\InvalidConfigException;

use function in_array;
use function is_resource;
use function ssh2_disconnect;

/**
 * Файловая система SFTP
 *
 */
class SftpFileStore extends LocalFileStore
{
    /** @var string сервер */
    public $host;

    /** @var int порт сервера */
    public $port = 22;

    /** @var string логин пользователя */
    public $username;

    /** @var string пароль для парольной авторизации */
    public $password;

    /** @var string путь к файлу с открытым ключом в формате OpenSSH для авторизации ключом */
    public $pubkeyfile;

    /** @var string путь к файлу приватного ключа */
    public $privkeyfile;

    /** @var string пароль приватного ключа */
    public $passphrase;

    /** @var resource connection */
    private $session;

    /** @var resource SFTP */
    private $sftp;

    /**
     * @inheritDoc
     * @throws StoreException
     */
    public function init() : void
    {
        $this->host = trim($this->host);
        if ($this->host === '') {
            throw new InvalidConfigException('host');
        }

        $this->port = (int)$this->port;
        if (empty($this->port)) {
            throw new InvalidConfigException('port');
        }

        $this->session = @ssh2_connect($this->host, $this->port);
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
    public function setPath(string $path) : LocalFileStore
    {
        $this->_path = '/' . $this->normalizePath($path);
        return $this;
    }

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function list($path, array $filter = []) : array
    {
        $absPath = $this->absolutePath($path);

        $dir = @opendir($absPath, /** @scrutinizer ignore-type */ $this->context);
        if ($dir === false) {
            $this->throwLastError('Чтение каталога', $absPath);
        }

        $files = [];

        try {
            while (($item = readdir($dir)) !== false) {
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
                @closedir($dir);
            }
        }

        return self::sortByName($files);
    }

    /**
     * @inheritDoc
     */
    public function absolutePath($path) : string
    {
        return 'ssh2.sftp://' . (int)$this->sftp . $this->relativePath($path);
    }

    /**
     * Возвращает относительный путь
     *
     * @param string|array $path
     * @return string
     */
    public function relativePath($path): string
    {
        return parent::absolutePath($path);
    }

    /**
     * @inheritDoc
     */
    public function setPublic($path, bool $public) : AbstractFileStore
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
    public function rename($path, $newpath) : AbstractFileStore
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
    public function mkdir($path) : AbstractFileStore
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
    protected function unlink($path) : AbstractFileStore
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
    protected function rmdir($path) : AbstractFileStore
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
