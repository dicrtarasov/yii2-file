<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 28.09.20 02:45:27
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
class FlysystemFileStoreTest extends AbstractFileStoreTest
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
