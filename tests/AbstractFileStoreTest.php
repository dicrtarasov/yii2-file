<?php
namespace dicr\tests;

use PHPUnit\Framework\TestCase;
use dicr\file\AbstractFileStore;
use dicr\file\StoreException;
use dicr\file\StoreFile;
use Yii;
use yii\di\Container;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
abstract class AbstractFileStoreTest extends TestCase
{
    /** @var id компонента тестового файлового хранилища */
    const STORE_ID = 'fileStore';

    /**
     * {@inheritdoc}
     *
     * @return \yii\console\Application
     */
    public static function setUpBeforeClass(): void
    {
        new \yii\console\Application([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => VENDOR,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public static function tearDownAfterClass(): void
    {
        Yii::$app = null;
        Yii::$container = new Container();
    }

    /**
     * Возвращает тестовое хранилище.
     *
     * @return \dicr\file\AbstractFileStore
     */
    protected static function store()
    {
        return \Yii::$app->get(self::STORE_ID);
    }

    /**
     * Test store configured
     */
    public function testComponentExists()
    {
        $store = static::store();

        self::assertInstanceOf(AbstractFileStore::class, $store);
        self::assertInstanceOf(StoreFile::class, $store->file(''));
    }

    /**
     * Проверка на исключение когда путь выше родительского.
     */
    public function testNormalizeInvalidPath()
    {
        $store = static::store();

        self::expectException(StoreException::class);

        $store->normalizePath('/././../.');
    }

    /**
     * Проверка нормализации путей
     */
    public function testNormalizePath()
    {
        $store = static::store();

        $tests = [
            ''  => '',
            '/' => '',
            '/dir/to/file' => 'dir/to/file',
        ];

        foreach ($tests as $quetion => $answer) {
            self::assertSame($answer, $store->normalizePath($quetion));
        }

        self::expectException(\InvalidArgumentException::class);
        $store->file('')->child('')->path;

        self::assertEquals('', $store->file('/')->path);
        self::assertEquals('', $store->file('/')->child('/')->path);
        self::assertEquals('123', $store->file('123')->child('')->path);
        self::assertEquals('345', $store->file('')->child('345')->path);
        self::assertEquals('123/345', $store->file('123')->child('345')->path);

        self::assertEquals('d1/d2', $store->file('d1/d2/f1/')->dir);
        self::assertEquals('f1.dat', $store->file('/d1/d2/f1.dat/')->name);

        $file = $store->file('d1/d2/f1');
        self::assertEquals($file, $file->setName('/f2/'));
        self::assertEquals('d1/d2/f2', $file->path);
    }

    public function testPathRelations()
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

        self::expectException(StoreException::class);
        $store->file('')->name;

        // child
        self::assertSame('1/2/3/4', $file->child('4'));
    }

    public function testType()
    {
        $store = static::store();

        $file = $store->file('test-dir');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->mkdir());
        }

        self::assertTrue($file->isDir);
        self::assertFalse($file->isFile);

        $file = $store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertFalse($file->isDir);
        self::assertTrue($file->isFile);

        self::assertFalse($store->file(md5(time()))->isDir);
        self::assertFalse($store->file(md5(time()))->isDir);
        self::assertFalse($store->file(md5(time()))->exists);
    }

    public function testPublic()
    {
        $store = static::store();

        $file = $store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertInstanceOf(StoreFile::class, $file->setPublic(false));
        self::assertFalse($file->public);
        self::assertInstanceOf(StoreFile::class, $file->setPublic(true));
        self::assertTrue($file->public);

        self::expectException(StoreException::class);
        $store->file(md5(time()))->public;

        self::expectException(StoreException::class);
        $store->file(md5(time()))->public = true;
    }

    public function testHidden()
    {
        $store = static::store();

        self::assertFalse($store->file('')->hidden);

        self::assertFalse($store->file('s/s')->hidden);
        self::assertFalse($store->file('.s/s')->hidden);
        self::assertTrue($store->file('.s')->hidden);
        self::assertTrue($store->file('s/.s')->hidden);
    }

    public function testSize()
    {
        $store = static::store();

        $file = $store->file('test-file');

        self::assertInstanceOf(StoreFile::class, $file->setContents('1234567890'));
        self::assertEquals(10, $file->size);
        self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        self::assertEquals(0, $file->size);

        self::expectException(StoreException::class);
        $store->file(md5(time()))->size;
    }

    public function testMtime()
    {
        $store = static::store();

        $file = $store->file('test-file');
        self::assertGreaterThanOrEqual(time(), $file->setContents('')->mtime);

        self::expectException(StoreException::class);
        $store->file(md5(time()))->mtime;
    }

    public function testMimeType()
    {
        $store = static::store();

        $file = $store->file('test-file');
        $file->contents = "text file\ntest content\n";
        self::assertContains($file->mimeType, ['text/plain', 'inode/x-empty']);

        self::expectException(StoreException::class);
        $store->file(md5(time()))->mimeType;
    }

    public function testContents()
    {
        $store = static::store();

        self::assertInstanceOf(StoreFile::class, $store->file('test-file')->setContents('12345'));
        self::assertSame(5, $store->file('test-file')->size);

        self::assertSame('12345', $store->file('test-file')->contents);

        self::expectException(StoreException::class);
        $store->file(md5(time()))->contents;

        self::expectException(StoreException::class);
        $store->file(md5(time()))->contents = 123;
    }

    public function testStream()
    {
        $store = static::store();

        $stream = fopen('php://temp', 'wt');
        fputs($stream, 'test');
        rewind($stream);

        self::assertInstanceOf(StoreFile::class, $store->file('test-file')->setStream($stream));
        self::assertSame(4, $store->file('test-file')->size);

        $stream = $store->file('test-file')->stream;
        self::assertInternalType('resource', $stream);
        self::assertSame('test', stream_get_contents($stream));

        self::expectException(StoreException::class);
        $store->file(md5(time()))->contents;

        self::expectException(StoreException::class);
        $store->file(md5(time()))->contents = 123;
    }

    public function testExistsDelete()
    {
        $store = static::store();

        $dir = $store->file('test-dir');
        if (!$dir->exists) {
            self::assertInstanceOf(StoreFile::class, $dir->mkdir());
        }

        self::assertTrue($dir->isDir);

        self::assertInstanceOf(StoreFile::class, $dir->delete());
        self::assertFalse($dir->exists);

        $file = $store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents('123'));
        }

        self::assertTrue($file->exists);
        self::assertTrue($file->isFile);

        self::assertInstanceOf(StoreFile::class, $file->delete());
        self::assertFalse($file->exists);

        self::assertInstanceOf(StoreFile::class, $store->file(md5(time()))->delete());
    }

    public function testFileListChild()
    {
        $store = static::store();

        self::expectException(StoreException::class);
        $store->list('123');

        $dir = $store->file('test-dir');
        self::assertInstanceOf(StoreFile::class, $dir);
        if (!$dir->exists) {
            self::assertInstanceOf(StoreFile::class, $dir->mkdir());
        }

        $file = $store->file('test-file');
        self::assertInstanceOf(StoreFile::class, $file);
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertCount(2, $store->list('', [
            'regex' => '~^test\-~'
        ]));

        self::assertInstanceOf(StoreFile::class, $dir->delete());
        self::assertInstanceOf(StoreFile::class, $file->delete());
    }
}
