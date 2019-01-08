<?php
namespace dicr\tests;

use League\Flysystem\Sftp\SftpAdapter;
use dicr\file\FlysystemFileStore;

/**
 * Sftp adapter flysystem test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class SftpFlysystemStoreTest extends AbstractFileStoreTest
{

    public function setUp()
    {
        $this->config['components']['fileStore'] = [
            'class' => FlysystemFileStore::class,
            'flysystem' => function(FlysystemFileStore $fileStore) {
                $adapter = new SftpAdapter([
                    'host' => 'server.net',
                    'username' => 'test',
                    'password' => 'test',
                    'root' => '/tests',
                    'permPrivate' => 0700,
                    'permPublic' => 0777
                ]);

                return new \League\Flysystem\Filesystem($adapter);
            }
        ];

        parent::setUp();
    }
}
