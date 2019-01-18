<?php
namespace dicr\tests;

use dicr\file\SftpFileStore;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class SftpFileStoreTest extends AbstractFileStoreTest
{
    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        require(__DIR__.'/conf.remote.php');

        $this->config['components']['fileStore'] = [
            'class' => SftpFileStore::class,
            'host' => FTP_HOST,
            'username' => FTP_LOGIN,
            'password' => FTP_PASSWD,
            'path' => '/tests'
        ];

        parent::setUp();
    }
}
