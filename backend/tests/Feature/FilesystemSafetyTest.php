<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

class FilesystemSafetyTest extends TestCase
{
    public function test_private_local_disk_cannot_serve_signed_files_over_http(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertSame('private', config('filesystems.disks.local.visibility'));
        $this->assertSame('private', config('filesystems.disks.local.directory_visibility'));
        $this->assertFalse(Route::has('storage.local'));
    }

    public function test_s3_compatible_disk_defaults_to_private_visibility(): void
    {
        $this->assertTrue(class_exists(AwsS3V3Adapter::class));
        $this->assertSame('s3', config('filesystems.disks.s3.driver'));
        $this->assertSame('private', config('filesystems.disks.s3.visibility'));
        $this->assertSame('private', config('filesystems.disks.s3.directory_visibility'));
    }
}
