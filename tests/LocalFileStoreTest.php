<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.01.21 19:24:14
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\LocalFileStore;
use Yii;
use yii\base\InvalidConfigException;

/**
 * LocalStore Test
 */
class LocalFileStoreTest extends FileStoreTest
{
    /**
     * @inheritdoc
     * @throws InvalidConfigException
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
     * @throws InvalidConfigException
     */
    public function testAbsolutePath() : void
    {
        $store = static::store();

        self::assertSame(__DIR__ . '/files/test', $store->file('test')->absolutePath);
        self::assertSame('/test/file', LocalFileStore::root()->file('test/file')->absolutePath);
    }
}
