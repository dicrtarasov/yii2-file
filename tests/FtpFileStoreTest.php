<?php
namespace dicr\tests;

use dicr\file\FtpFileStore;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class FtpFileStoreTest extends AbstractFileStoreTest
{
    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        $remote = require(__DIR__.'/conf.remote.php');

        $this->config['components']['fileStore'] = [
            'class' => FtpFileStore::class,
            'host' => $remote['host'],
            'username' => $remote['login'],
            'password' => $remote['passwd'],
            'path' => $remote['path'] ?? '/'
        ];

        parent::setUp();
    }
}
