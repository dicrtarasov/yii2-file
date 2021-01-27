<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.01.21 19:24:14
 */

declare(strict_types=1);
namespace dicr\tests;

use dicr\file\FlysystemFileStore;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Yii;
use yii\base\InvalidConfigException;

/**
 * Flysystem file store test
 */
class FlysystemFileStoreTest extends FileStoreTest
{
    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Yii::$app->set(self::STORE_ID, [
            'class' => FlysystemFileStore::class,
            'flysystem' => static function () : Filesystem {
                $adapter = new Local(__DIR__ . '/files', LOCK_EX, Local::SKIP_LINKS);

                return new Filesystem($adapter);
            }
        ]);
    }
}
