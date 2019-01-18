<?php
namespace dicr\file;

use yii\base\InvalidConfigException;

/**
 * Файловая система FTP
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 * @see http://php.net/manual/en/context.ftp.php
 * @see http://php.net/manual/ru/wrappers.ftp.php
 * @see http://php.net/manual/ru/ref.ftp.php
 * @todo isPublic not supported
 * @todo mimeType not supported
 */
class FtpFileStore extends LocalFileStore
{
    /** @var string сервер */
    public $host;

    /** @var int порт сервера */
    public $port = 21;

    /** @var string логин пользователя */
    public $username = 'anonymous';

    /** @var string пароль для парольной авторизации */
    public $password;

    /** @var int таймаут сетевых операций */
    public $timeout = 90;

    /** @var resource connection */
    private $connection;

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
        if (!isset($this->context['ftp']['overwrite'])) {
            $this->context['ftp']['overwrite'] = true;
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
        return 'ftp://' . $this->username . ':' . $this->password . '@' . $this->host . ':' . $this->port . $this->relativePath($path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\LocalFileStore::list()
     */
    public function list($path, array $filter = [])
    {
        // для FTP нужно проверять существование директории, иначе всегда возвращает пустой список
        if (!$this->exists($path) || !$this->isDir($path)) {
            throw new StoreException('not a directory: ' . $this->normalizePath($path));
        }

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
        $perms = $this->permsByPublic($this->isDir($path), $public);
        $relativePath = $this->relativePath($path);

        if (! @ftp_chmod($this->connection, $perms, $relativePath)) {
            throw new StoreException('шибка установки прав: ' . $relativePath, StoreException(''));
        }

        clearstatcache(null, $this->absolutePath($path));

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\LocalFileStore::size()
     */
    public function size($path)
    {
        $size = @ftp_size($this->connection, $this->relativePath($path));
        if ($size < 0) {
            throw new StoreException('');
        }

        return $size;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\LocalFileStore::mtime()
     */
    public function mtime($path)
    {
        $time = @ftp_mdtm($this->connection, $this->relativePath($path));
        if ($time < 0) {
            throw new StoreException('');
        }

        return $time;
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

        if (! @ftp_rename($this->connection, $this->relativePath($path), $this->relativePath($newpath))) {
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

        if (! @ftp_mkdir($this->connection, $this->relativePath($path))) {
            throw new StoreException('');
        }

        $this->setPublic($path, $this->public);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\LocalFileStore::unlink()
     */
    protected function unlink($path)
    {
        $this->guardRootPath($path);

        if (! @ftp_delete($this->connection, $this->relativePath($path))) {
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

        if (! @ftp_rmdir($this->connection, $this->relativePath($path))) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * Деструктор
     */
    public function __destruct() {
        if (!empty($this->connection)) {
            @ftp_close($this->connection);
        }
    }
}