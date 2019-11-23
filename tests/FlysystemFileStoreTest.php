<?php
/**
 * @copyright 2019-2019 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 24.11.19 00:29:11
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\FlysystemFileStore;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Yii;

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

        Yii::$app->set(self::STORE_ID, [
            'class' => FlysystemFileStore::class,
            'flysystem' => static function() {
                $adapter = new Local(__DIR__ . '/files', LOCK_EX, Local::SKIP_LINKS);

                return new Filesystem($adapter);
            }
        ]);
    }
}
