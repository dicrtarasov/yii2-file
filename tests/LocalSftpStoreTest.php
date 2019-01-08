<?php
namespace dicr\tests;

use dicr\file\LocalFileStore;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class LocalSftpFileStoreTest extends AbstractFileStoreTest
{
    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        $this->config['components']['fileStore'] = [
            'class' => LocalFileStore::class,
            'path' => 'ssh2.sftp://test:test@server.net/tests'
        ];

        parent::setUp();
    }
}
