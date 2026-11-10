<?php
declare(strict_types=1);

namespace ListenLab\Tests\Unit;

use ListenLab\AppError;
use ListenLab\Storage\RawStore;
use ListenLab\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class RawStoreTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void { $this->createTemporary(); }
    protected function tearDown(): void { $this->removeTemporary(); }

    public function testPreservesExactBytesAndNeverReusesAName(): void
    {
        $store = new RawStore($this->temporary);
        $bytes = " { \"b\": 2, \"a\": 1 }\r\n";
        $first = $store->save($bytes, 1);
        $second = $store->save($bytes, 1);
        self::assertNotSame($first->path, $second->path);
        self::assertSame($first->sha256, $second->sha256);
        self::assertSame($bytes, $store->readVerified($first->path, $first->sha256));
        self::assertSame(strlen($bytes), $first->sizeBytes);
        self::assertSame(0440, fileperms($this->temporary . '/' . $first->path) & 0777);
    }

    public function testTamperedRawIsRejected(): void
    {
        $store = new RawStore($this->temporary);
        $artifact = $store->save('{}', 1);
        chmod($this->temporary . '/' . $artifact->path, 0600);
        file_put_contents($this->temporary . '/' . $artifact->path, 'changed');
        $this->expectException(AppError::class);
        $store->readVerified($artifact->path, $artifact->sha256);
    }

    public function testTraversalCannotReadOutsideRawRoot(): void
    {
        $store = new RawStore($this->temporary . '/raw');
        mkdir($this->temporary . '/raw');
        file_put_contents($this->temporary . '/other', '{}');
        $this->expectException(AppError::class);
        $store->readVerified('../other', hash('sha256', '{}'));
    }

    public function testSharedCompressedContentPreservesWhitespaceAndEveryReceipt(): void
    {
        $store=new RawStore($this->temporary.'/raw');
        $body=" {\"original\":\"".str_repeat('same bytes ',2000)."\"} \r\n";
        $first=$store->save($body,1);$second=$store->save($body,2);
        self::assertNotSame($first->path,$second->path);
        self::assertCount(1,glob($this->temporary.'/raw/objects/*/*.json.gz'));
        self::assertSame($body,$store->readVerified($first->path,$first->sha256));
        self::assertSame($body,$store->readVerified($second->path,$second->sha256));
        self::assertLessThan(strlen($body),$store->objectInfo($first->sha256)['stored_bytes']);
    }

    public function testLegacyLiteralThatResemblesAReferenceIsStillReadLiterally(): void
    {
        mkdir($this->temporary.'/raw');
        $body='{"listenlab_raw_reference":1,"sha256":"not-a-hash","size_bytes":0}';
        file_put_contents($this->temporary.'/raw/legacy.json',$body);
        self::assertSame($body,(new RawStore($this->temporary.'/raw'))->readVerified('legacy.json',hash('sha256',$body)));
    }

    public function testCorruptCompressedContentIsRejected(): void
    {
        $store=new RawStore($this->temporary.'/raw');$artifact=$store->save(str_repeat('original',1000),1);
        $object=glob($this->temporary.'/raw/objects/*/*.json.gz')[0];chmod($object,0600);file_put_contents($object,'invalid gzip');
        $this->expectException(AppError::class);$store->readVerified($artifact->path,$artifact->sha256);
    }

    public function testArchiveCannotBeInsideTheActiveRawDirectory(): void
    {
        $this->expectException(AppError::class);
        new RawStore($this->temporary.'/raw',$this->temporary.'/raw/archive');
    }

    public function testArchiveCorruptionPreservesTheLocalObject(): void
    {
        $store=new RawStore($this->temporary.'/raw',$this->temporary.'/archive');
        $body=str_repeat('original',1000);$artifact=$store->save($body,1);$store->backupObject($body);
        $backup=glob($this->temporary.'/archive/objects/*/*.json.gz')[0];chmod($backup,0600);file_put_contents($backup,'damaged');
        try{$store->evictLocal($artifact->sha256);self::fail('Corrupt backup must prevent eviction.');}catch(AppError){}
        self::assertSame('local',$store->objectInfo($artifact->sha256)['tier']);
        self::assertSame($body,$store->readVerified($artifact->path,$artifact->sha256));
    }
}
