<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\LocalFileStore;
use Yii;

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
     * @throws \yii\base\InvalidConfigException
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Yii::$app->set(self::STORE_ID, [
            'class' => LocalFileStore::class,
            'path' => __DIR__ . '/files'
        ]);
    }

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function testAbsolutePath()
    {
        $store = static::store();

        self::assertSame(__DIR__ . '/files/test', $store->file('test')->absolutePath);
        self::assertSame('/test/file', LocalFileStore::root()->file('test/file')->absolutePath);
    }
}
