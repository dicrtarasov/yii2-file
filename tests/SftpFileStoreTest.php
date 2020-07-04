<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.07.20 13:07:26
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\SftpFileStore;
use Yii;
use yii\base\InvalidConfigException;

/**
 * SFTP FileStore Test
 */
class SftpFileStoreTest extends AbstractFileStoreTest
{
    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Yii::$app->set(self::STORE_ID, [
            'class' => SftpFileStore::class,
            'host' => REMOTE_FILE_HOST,
            'username' => REMOTE_FILE_LOGIN,
            'password' => REMOTE_FILE_PASS,
            'path' => REMOTE_FILE_PATH
        ]);
    }
}
