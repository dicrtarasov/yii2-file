<?php
namespace dicr\file;

use yii\base\InvalidConfigException;

/**
 * Файловая система SFTP
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
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

    /** @var string путь к файлу с открытым ключем в формате OpenSSH для авторизации ключем */
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
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::init()
     */
    public function init()
    {
        $this->host = trim($this->host);
        if ($this->host === '') {
            throw new InvalidConfigException('host');
        }

        $this->port = (int) $this->port;
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
        } elseif (isset($this->pubkeyfile) && isset($this->privkeyfile)) {
            if (! @ssh2_auth_pubkey_file($this->session, $this->username, $this->pubkeyfile, $this->privkeyfile,
                $this->passphrase)) {
                throw new StoreException('ошибка авторизации по открытому ключу');
            }
        } else {
            throw new InvalidConfigException('требуется passord или pubkeyfile для авторизации');
        }

        $this->sftp = @ssh2_sftp($this->session);
        if (empty($this->sftp)) {
            throw new StoreException('ошибка инициализации SFTP');
        }

        parent::init();
    }

    /**
     * Возвращает относительный путь
     *
     * @param string|array $path
     * @return string
     */
    public function relativePath($path)
    {
        return parent::absolutePath($path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::absolutePath()
     */
    public function absolutePath($path)
    {
        return 'ssh2.sftp://' . intval($this->sftp) . $this->relativePath($path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\LocalFileStore::list()
     */
    public function list($path, array $filter = [])
    {
        $dir = @opendir($this->absolutePath($path), $this->context);
        if (empty($dir)) {
            throw new StoreException('');
        }

        $files = [];

        try {
            while (($item = @readdir($dir)) !== false) {
                if ($item == '' || $item == '.' || $item == '..') {
                    continue;
                }

                $file = $this->file($this->childname($path, $item));

                if ($this->fileMatchFilter($file, $filter)) {
                    $files[] = $file;
                }

                if ($this->isDir($file->path)) {
                    $files = array_merge($files, $this->list($file->path, $filter));
                }
            }
        } finally {
            @closedir($dir);
        }

        usort($files, function ($a, $b) {
            return $a->path <=> $b->path;
        });

        return $files;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::setPublic()
     */
    public function setPublic($path, bool $public)
    {
        if (! $this->exists($path)) {
            throw new StoreException('not exists: ' . $this->guardRootPath($path));
        }

        $perms = $this->permsByPublic($this->isDir($path), $public);
        $relativePath = $this->relativePath($path);

        if (! @ssh2_sftp_chmod($this->sftp, $relativePath, $perms)) {
            throw new StoreException('шибка установки прав: ' . $relativePath, StoreException(''));
        }

        clearstatcache(null, $this->absolutePath($path));

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::move()
     */
    public function move($path, $newpath)
    {
        $path = $this->guardRootPath($path);
        $newpath = $this->guardRootPath($newpath);

        if ($path === $newpath) {
            throw new \LogicException('path == newpath');
        }

        $this->checkDir($this->dirname($newpath));

        if (! @ssh2_sftp_rename($this->sftp, $this->relativePath($path), $this->relativePath($newpath))) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::mkdir()
     */
    public function mkdir($path)
    {
        $path = $this->guardRootPath($path);

        if ($this->exists($path)) {
            throw new StoreException('уже существует: ' . $path);
        }

        $perms = $this->permsByPublic(true, $this->public);

        if (! @ssh2_sftp_mkdir($this->sftp, $this->relativePath($path), $perms, true)) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::unlink()
     */
    protected function unlink($path)
    {
        $this->guardRootPath($path);

        if (! @ssh2_sftp_unlink($this->sftp, $this->relativePath($path))) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::rmdir()
     */
    protected function rmdir($path)
    {
        $this->guardRootPath($path);

        if (! @ssh2_sftp_rmdir($this->sftp, $this->relativePath($path))) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        if (!empty($this->session)) {
            @ssh2_disconnect($this->session);
        }
    }
}