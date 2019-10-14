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
class FlysystemFileStoreTest extends AbstractFileStoreTest
{
    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        \Yii::$app->set(self::STORE_ID, [
            'class' => FlysystemFileStore::class,
            'flysystem' => function(FlysystemFileStore $fileStore) {
                $adapter = new Local(__DIR__.'/files', LOCK_EX, Local::SKIP_LINKS);

                return new \League\Flysystem\Filesystem($adapter);
            }
        ]);
    }
}
