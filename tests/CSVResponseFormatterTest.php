<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 22.01.21 16:12:37
 */

/**
 * @author Igor A Tarasov <develop@dicr.org>
 * @version 08.07.20 07:43:31
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\CSVFile;
use dicr\file\CSVResponseFormatter;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\DynamicModel;
use yii\base\Exception;

/**
 * Test
 */
class CSVResponseFormatterTest extends TestCase
{
    /** @var array test data */
    protected static $testData = [];

    /**
     * set up
     */
    public static function setUpBeforeClass(): void
    {
        self::$testData = array_map(
            static fn(array $config): DynamicModel => new DynamicModel($config), [
                ['name' => 'Иванов Иван Иванович', 'phone' => '+79996341261', 'Проверка'],
                ['name' => "Имя\n", 'phone' => "\"\\;,-<>'", 'comment' => "\r\n\n "]
            ]
        );
    }

    /**
     * Test response format
     *
     * @throws Exception
     */
    public function testResponse(): void
    {
        $csvFormat = new CSVResponseFormatter([
            'contentType' => CSVResponseFormatter::CONTENT_TYPE_EXCEL,
            'fileName' => 'test.csv',
            'csvConfig' => [
                'charset' => CSVFile::CHARSET_EXCEL,
                'delimiter' => CSVFile::DELIMITER_EXCEL,
                'escape' => CSVFile::ESCAPE_DEFAULT,
            ],
            'fields' => [
                'name' => 'Имя', 'phone' => 'Телефон', 'comment' => 'Комментарий'
            ],
        ]);

        $response = Yii::$app->response;
        $response->data = self::$testData;
        $response = $csvFormat->format($response);

        self::assertNull($response->data);
        self::assertEquals('attachment; filename="test.csv"', $response->headers->get('content-disposition'));
        self::assertEquals('application/vnd.ms-excel; charset=windows-1251', $response->headers->get('content-type'));
        self::assertEquals(87, $response->headers->get('content-length'));
        self::assertIsResource($response->stream);
    }
}
