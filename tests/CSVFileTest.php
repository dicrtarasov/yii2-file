<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 22.05.21 21:42:34
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\CSVFile;
use PHPUnit\Framework\TestCase;
use yii\base\Exception;

/**
 * Test CSVFile
 */
class CSVFileTest extends TestCase
{
    /** @var array тестовые данные */
    public const TEST_DATA = [
        ["Иван\r\nИванович", '+7(099)332-43-56', -1.1, 0, 1.12, '', "\n"],
        ['Александр Васильевич', 0, '";,']
    ];

    /**
     * Тест
     *
     * @throws Exception
     * @noinspection PhpUnitMissingTargetForTestInspection
     */
    public function testReadWrite() : void
    {
        $csvFile = new CSVFile([
            'charset' => 'cp1251',
        ]);

        // записываем объекты в файл
        foreach (self::TEST_DATA as $line) {
            self::assertGreaterThan(0, $csvFile->writeLine($line));
        }

        // проверяем номер текущей строки
        self::assertSame(1, $csvFile->lineNo);

        // сбрасываем
        $csvFile->reset();
        self::assertNull($csvFile->lineNo);

        // выбираем обратно через итерацию
        $data = [];
        foreach ($csvFile as $line) {
            $data[] = $line;
        }

        self::assertEquals(self::TEST_DATA, $data);
        self::assertNull($csvFile->current());
        self::assertSame(1, $csvFile->lineNo);
    }
}
