<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 */

declare(strict_types = 1);

namespace dicr\tests;

use dicr\file\FtpFileStore;
use Yii;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class FtpFileStoreTest extends AbstractFileStoreTest
{
    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Yii::$app->set(self::STORE_ID, [
            'class' => FtpFileStore::class,
            'host' => REMOTE_FILE_HOST,
            'username' => REMOTE_FILE_LOGIN,
            'password' => REMOTE_FILE_PASS,
            'path' => REMOTE_FILE_PATH
        ]);
    }
}
