<?php
namespace dicr\tests;

use League\Flysystem\Adapter\Ftp;
use dicr\file\FlysystemFileStore;

/**
 * Ftp file store test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class FtpFlysystemStoreTest extends AbstractFileStoreTest {

    public function setUp() {

        $this->config['components']['fileStore'] = [
            'class' => FlysystemFileStore::class,
            'flysystem' => function(FlysystemFileStore $fileStore) {
                $adapter = new Ftp([
                    'host' => 'server.net',
                    'username' => 'test',
                    'password' => 'test',
                    'root' => '/tests',
                    'permPrivate' => 0700,
                    'permPublic' => 0777,
                    'utf8' => 1
                ]);

                return new \League\Flysystem\Filesystem($adapter);
            }
        ];

        parent::setUp();
    }

}
