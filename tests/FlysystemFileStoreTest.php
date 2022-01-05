<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 06.01.22 00:27:31
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\FlysystemFileStore;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
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
            'flySystem' => static fn() => new Filesystem(
                new LocalFilesystemAdapter(__DIR__ . '/files')
            )
        ]);
    }
}
