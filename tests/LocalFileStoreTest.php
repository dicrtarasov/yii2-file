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
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        \Yii::$app->set(self::STORE_ID, [
            'class' => LocalFileStore::class,
            'path' => __DIR__ . '/files'
        ]);
    }

    public function testAbsolutePath()
    {
        $store = static::store();

        self::assertEquals(__DIR__ . '/files/test', $store->file('test')->absolutePath);
        self::assertEquals('/test/file', LocalFileStore::root()->file('test/file')->absolutePath);
    }
}
