<?php
namespace dicr\tests;

use League\Flysystem\Adapter\Local;
use dicr\file\FlysystemFileStore;

/**
 * Ftp file store test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class LocalFlysystemStoreTest extends AbstractFileStoreTest {

    public function setUp() {

        $this->config['components']['fileStore'] = [
            'class' => FlysystemFileStore::class,
            'flysystem' => function(FlysystemFileStore $fileStore) {
                $adapter = new Local(__DIR__.'/files', LOCK_EX, Local::SKIP_LINKS);
                return new \League\Flysystem\Filesystem($adapter);
            }
        ];

        parent::setUp();
    }

}
