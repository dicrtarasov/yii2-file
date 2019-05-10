<?php
namespace dicr\tests;

use dicr\file\LocalFileStore;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class LocalFileStoreTest extends AbstractFileStoreTest
{
    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        $this->config['components']['fileStore'] = [
            'class' => LocalFileStore::class,
            'path' => __DIR__.'/files'
        ];

        parent::setUp();
    }

    public function testAbsolutePath()
    {
        self::assertEquals(__DIR__ . '/files/test', $this->store->file('test')->absolutePath);
        self::assertEquals('/test/file', LocalFileStore::root()->file('test/file')->absolutePath);
    }
}
