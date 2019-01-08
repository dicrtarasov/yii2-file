<?php
namespace dicr\tests;

use dicr\file\File;
use dicr\file\FileStoreInterface;
use dicr\file\StoreException;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
abstract class AbstractFileStoreTest extends TestCase
{
    /** @var \dicr\file\AbstractFileStore */
    protected $store;

    /**
     * {@inheritDoc}
     * @see \dicr\tests\TestCase::setUp()
     */
    public function setUp() {
        parent::setUp();
        $this->store = \Yii::$app->fileStore;
    }

    /**
     * Test store configured
     */
    public function testComponentExists()
    {
        self::assertInstanceOf(FileStoreInterface::class, $this->store);
        self::assertInstanceOf(FileStoreInterface::class, $this->store->file('')->store);
    }

    public function testPathName() {
        self::assertEquals('', $this->store->file('')->child('')->path);
        self::assertEquals('', $this->store->file('/')->path);
        self::assertEquals('', $this->store->file('/')->child('/')->path);
        self::assertEquals('123', $this->store->file('123')->child('')->path);
        self::assertEquals('345', $this->store->file('')->child('345')->path);
        self::assertEquals('123/345', $this->store->file('123')->child('345')->path);

        self::assertEquals('d1/d2', $this->store->file('d1/d2/f1/')->dir);
        self::assertEquals('f1.dat', $this->store->file('/d1/d2/f1.dat/')->name);

        $file = $this->store->file('d1/d2/f1');
        self::assertEquals($file, $file->setName('/f2/'));
        self::assertEquals('d1/d2/f2', $file->path);
    }

    public function testType() {
        $file = $this->store->file('test-dir');
        if (!$file->exists) {
            self::assertInstanceOf(File::class, $file->mkdir());
        }

        self::assertEquals(File::TYPE_DIR, $file->type);
        self::assertTrue($file->isDir);
        self::assertFalse($file->isFile);

        $file = $this->store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(File::class, $file->setContents(''));
        }

        self::assertEquals(File::TYPE_FILE, $file->type);
        self::assertFalse($file->isDir);
        self::assertTrue($file->isFile);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->type;
    }

    public function testAccess() {
        $file = $this->store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(File::class, $file->setContents(''));
        }

        self::assertInstanceOf(File::class, $file->setAccess(File::ACCESS_PRIVATE));
        self::assertEquals(File::ACCESS_PRIVATE, $file->access);
        self::assertFalse($file->isPublic);

        self::assertInstanceOf(File::class, $file->setAccess(File::ACCESS_PUBLIC));
        self::assertEquals(File::ACCESS_PUBLIC, $file->access);
        self::assertTrue($file->isPublic);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->access;

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->access = File::ACCESS_PRIVATE;
    }

    public function testHidden() {
        self::assertFalse($this->store->file('')->isHidden);
        self::assertFalse($this->store->file('s/s')->isHidden);
        self::assertFalse($this->store->file('.s/s')->isHidden);
        self::assertTrue($this->store->file('.s')->isHidden);
        self::assertTrue($this->store->file('s/.s')->isHidden);
    }

    public function testSize() {
        $file = $this->store->file('test-file');

        self::assertInstanceOf(File::class, $file->setContents('1234567890'));
        self::assertEquals(10, $file->size);
        self::assertInstanceOf(File::class, $file->setContents(''));
        self::assertEquals(0, $file->size);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->size;
    }

    public function testMtime() {
        self::assertGreaterThanOrEqual(time(), $this->store->file('test-file')->setContents('')->mtime);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->mtime;
    }

    public function testMimeType() {
        self::assertContains($this->store->file('test-file')->mimeType, ['text/plain', 'inode/x-empty']);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->mimeType;
    }

    public function testContents() {
        self::assertInstanceOf(File::class, $this->store->file('test-file')->setContents('12345'));
        self::assertSame(5, $this->store->file('test-file')->size);

        self::assertSame('12345', $this->store->file('test-file')->contents);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents;

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents = 123;
    }

    public function testStream() {

        $stream = fopen('php://temp', 'wt');
        fputs($stream, 'test');
        rewind($stream);

        self::assertInstanceOf(File::class, $this->store->file('test-file')->setStream($stream));
        self::assertSame(4, $this->store->file('test-file')->size);

        $stream = $this->store->file('test-file')->stream;
        self::assertInternalType('resource', $stream);
        self::assertSame('test', stream_get_contents($stream));

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents;

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents = 123;
    }

    public function testExistsDelete() {
        $dir = $this->store->file('test-dir');
        if (!$dir->exists) {
            self::assertInstanceOf(File::class, $dir->mkdir());
        }

        self::assertTrue($dir->exists);
        self::assertTrue($dir->isDir);

        self::assertInstanceOf(File::class, $dir->delete());
        self::assertFalse($dir->exists);

        $file = $this->store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(File::class, $file->setContents('123'));
        }

        self::assertTrue($file->exists);
        self::assertTrue($file->isFile);

        self::assertInstanceOf(File::class, $file->delete());
        self::assertFalse($file->exists);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->delete();
    }

    public function testFileListChild() {
        self::assertCount(0, $this->store->list('123'));

        $dir = $this->store->file('test-dir');
        self::assertInstanceOf(File::class, $dir);
        if (!$dir->exists) {
            self::assertInstanceOf(File::class, $dir->mkdir());
        }

        $file = $this->store->file('test-file');
        self::assertInstanceOf(File::class, $file);
        if (!$file->exists) {
            self::assertInstanceOf(File::class, $file->setContents(''));
        }

        self::assertCount(2, $this->store->list('', [
            'regex' => '~^test\-~'
        ]));

        self::assertInstanceOf(File::class, $dir->delete());
        self::assertInstanceOf(File::class, $file->delete());
    }
}