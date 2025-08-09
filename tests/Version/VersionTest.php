<?php

namespace TrackPHP\Tests\Version;

use PHPUnit\Framework\TestCase;
use TrackPHP\Version\Version;

final class VersionTest extends TestCase
{
    public function test_version_looks_like_semver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/',
            Version::current()
        );
    }
}

