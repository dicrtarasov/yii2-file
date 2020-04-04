<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.04.20 20:11:07
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\T;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\Application;

/**
 * Class TTest
 *
 * @package dicr\tests
 */
class TTest extends TestCase
{
    /**
     * {@inheritdoc}
     *
     * @return Application
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     */
    public static function setUpBeforeClass(): void
    {
        new Application([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => VENDOR,
        ]);
    }

    /**
     * @noinspection SpellCheckingInspection
     * @noinspection PhpMethodMayBeStaticInspection
     */
    public function testT()
    {
        Yii::$app->language = 'ua';
        self::assertSame('Додати', T::t('Добавить'));
    }
}
