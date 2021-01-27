<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.01.21 19:27:00
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\file\FileStore;
use dicr\file\StoreException;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * LocalStore Test
 */
abstract class FileStoreTest extends TestCase
{
    /** @var string id компонента тестового файлового хранилища */
    public const STORE_ID = 'fileStore';

    /**
     * Test store configured
     *
     * @throws InvalidConfigException
     */
    public function testComponentExists(): void
    {
        $store = static::store();

        /** @noinspection UnnecessaryAssertionInspection */
        self::assertInstanceOf(FileStore::class, $store);
        self::assertNotEmpty($store->file(''));
    }

    /**
     * Возвращает тестовое хранилище.
     *
     * @return FileStore
     * @throws InvalidConfigException
     */
    protected static function store(): FileStore
    {
        return Yii::$app->get(self::STORE_ID);
    }

    /**
     * Проверка на исключение когда путь выше родительского.
     *
     * @throws InvalidConfigException
     */
    public function testNormalizeInvalidPath() : void
    {
        $store = static::store();

        $this->expectException(InvalidArgumentException::class);

        $store->normalizePath('/././../.');
    }

    /**
     * Проверка нормализации путей
     *
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testNormalizePath() : void
    {
        $store = static::store();

        $tests = [
            '' => '',
            '/' => '',
            '/dir/to/file' => 'dir/to/file',
        ];

        foreach ($tests as $question => $answer) {
            self::assertSame($answer, $store->normalizePath($question));
        }

        $this->expectException(InvalidArgumentException::class);
        $store->file('')->child('')->path;

        self::assertSame('', $store->file('/')->path);
        self::assertSame('', $store->file('/')->child('/')->path);
        self::assertSame('123', $store->file('123')->child('')->path);
        self::assertSame('345', $store->file('')->child('345')->path);
        self::assertSame('123/345', $store->file('123')->child('345')->path);

        self::assertSame('d1/d2', $store->file('d1/d2/f1/')->parent->path);
        self::assertSame('f1.dat', $store->file('/d1/d2/f1.dat/')->name);

        $file = $store->file('d1/d2/f1');
        self::assertEquals($file, $file->setName('/f2/'));
        self::assertSame('d1/d2/f2', $file->path);
    }

    /**
     * @throws InvalidConfigException
     */
    public function testPathRelations() : void
    {
        $store = static::store();

        self::assertNull($store->file('')->parent);

        $file = $store->file('/1/2/3/');

        // parent
        self::assertSame('1/2', $file->parent->path);
        self::assertSame('', $file->parent->parent->parent->path);
        self::assertNull($file->parent->parent->parent->parent);

        // basename
        self::assertSame('3', $file->name);

        $this->expectException(InvalidArgumentException::class);
        $store->file('')->name;

        // child
        self::assertSame('1/2/3/4', $file->child('4'));
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testType() : void
    {
        $store = static::store();

        $file = $store->file('test-dir');
        if (! $file->exists) {
            self::assertNotEmpty($file->mkdir());
        }

        self::assertTrue($file->isDir);
        self::assertFalse($file->isFile);

        $file = $store->file('test-file');
        if (! $file->exists) {
            self::assertNotEmpty($file->setContents(''));
        }

        self::assertFalse($file->isDir);
        self::assertTrue($file->isFile);

        self::assertFalse($store->file(md5((string)time()))->isDir);
        self::assertFalse($store->file(md5((string)time()))->isDir);
        self::assertFalse($store->file(md5((string)time()))->exists);
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testPublic() : void
    {
        $store = static::store();

        $file = $store->file('test-file');
        if (! $file->exists) {
            self::assertNotEmpty($file->setContents(''));
        }

        self::assertNotEmpty($file->setPublic(false));
        self::assertFalse($file->public);
        self::assertNotEmpty($file->setPublic(true));
        self::assertTrue($file->public);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->public;

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->public = true;
    }

    /**
     * @throws InvalidConfigException
     */
    public function testHidden() : void
    {
        $store = static::store();

        self::assertFalse($store->file('')->hidden);

        self::assertFalse($store->file('s/s')->hidden);
        self::assertFalse($store->file('.s/s')->hidden);
        self::assertTrue($store->file('.s')->hidden);
        self::assertTrue($store->file('s/.s')->hidden);
    }

    /**
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function testSize() : void
    {
        $store = static::store();

        $file = $store->file('test-file');

        self::assertNotEmpty($file->setContents('1234567890'));
        self::assertSame(10, $file->size);
        self::assertNotEmpty($file->setContents(''));
        self::assertSame(0, $file->size);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->size;
    }

    /**
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function testMtime() : void
    {
        $store = static::store();

        $file = $store->file('test-file');
        self::assertGreaterThanOrEqual(time(), $file->setContents('')->mtime);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->mtime;
    }

    /**
     * @throws InvalidConfigException
     */
    public function testMimeType() : void
    {
        $store = static::store();

        $file = $store->file('test-file');
        $file->contents = "text file\ntest content\n";
        self::assertContains($file->mimeType, ['text/plain', 'inode/x-empty']);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->mimeType;
    }

    /**
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function testContents() : void
    {
        $store = static::store();

        self::assertNotEmpty($store->file('test-file')->setContents('12345'));
        self::assertSame(5, $store->file('test-file')->size);

        self::assertSame('12345', $store->file('test-file')->contents);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents;

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents = 123;
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testStream() : void
    {
        $store = static::store();

        $stream = fopen('php://temp', 'wb');
        fwrite($stream, 'test');
        rewind($stream);

        self::assertNotEmpty($store->file('test-file')->setStream($stream));
        self::assertSame(4, $store->file('test-file')->size);

        $stream = $store->file('test-file')->stream;
        self::assertIsResource($stream);
        self::assertSame('test', stream_get_contents($stream));

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents;

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents = 123;
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testExistsDelete() : void
    {
        $store = static::store();

        $dir = $store->file('test-dir');
        if (! $dir->exists) {
            self::assertNotEmpty($dir->mkdir());
        }

        self::assertTrue($dir->isDir);

        self::assertNotEmpty($dir->delete());
        self::assertFalse($dir->exists);

        $file = $store->file('test-file');
        if (! $file->exists) {
            self::assertNotEmpty($file->setContents('123'));
        }

        self::assertTrue($file->exists);
        self::assertTrue($file->isFile);

        self::assertNotEmpty($file->delete());
        self::assertFalse($file->exists);

        self::assertNotEmpty($store->file(md5((string)time()))->delete());
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testFileListChild() : void
    {
        $store = static::store();

        $this->expectException(StoreException::class);
        $store->list('123');

        $dir = $store->file('test-dir');
        self::assertNotEmpty($dir);
        if (! $dir->exists) {
            self::assertNotEmpty($dir->mkdir());
        }

        $file = $store->file('test-file');
        self::assertNotEmpty($file);
        if (! $file->exists) {
            self::assertNotEmpty($file->setContents(''));
        }

        self::assertCount(2, $store->list('', [
            'regex' => '~^test\-~'
        ]));

        self::assertNotEmpty($dir->delete());
        self::assertNotEmpty($file->delete());
    }
}
