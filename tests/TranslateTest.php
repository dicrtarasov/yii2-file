<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 11.07.20 09:42:25
 */

declare(strict_types = 1);

namespace dicr\tests;

use PHPUnit\Framework\TestCase;
use Yii;

/**
 * Тест перевода.
 *
 * @package dicr\tests
 */
class TranslateTest extends TestCase
{
    /**
     * @inheritDoc
     */
    public static function setUpBeforeClass(): void
    {
        Yii::$app->language = 'ua';

        parent::setUpBeforeClass();
    }

    /**
     * Тест перевода.
     *
     * @noinspection PhpMethodMayBeStaticInspection
     */
    public function testTranslate()
    {
        /** @noinspection SpellCheckingInspection */
        self::assertSame('Додати', Yii::t('dicr/file', 'Добавить'));
    }
}
